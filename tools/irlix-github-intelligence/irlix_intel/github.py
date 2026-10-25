"""Read-only public GitHub REST client. Credentials never leave api.github.com."""
import base64
import email.utils
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request


class APIError(Exception):
    def __init__(self, status, message):
        self.status = status
        super().__init__(message)


class Pause(Exception):
    """Recoverable budget/rate-limit stop: caller leaves work pending."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def retry_delay(headers, fallback=60, now=None):
    now = time.time() if now is None else now
    value = headers.get('Retry-After')
    if value:
        try:
            return max(1, float(value))
        except ValueError:
            try:
                return max(1, email.utils.parsedate_to_datetime(value).timestamp() - now)
            except (TypeError, ValueError):
                pass
    if headers.get('X-RateLimit-Remaining') == '0':
        try:
            return max(1, float(headers['X-RateLimit-Reset']) - now + 2)
        except (KeyError, ValueError):
            pass
    return fallback


def repo_name(value):
    value = value.strip().removeprefix('https://github.com/').rstrip('/')
    if value.endswith('.git'):
        value = value[:-4]
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', value):
        raise ValueError('Expected GitHub owner/repo, got: ' + value[:100])
    return value.lower()


class GitHub:
    def __init__(self, token=None, max_requests=500, minutes=30, wait_limit=90,
                 opener=None, sleep=time.sleep, clock=time.monotonic):
        self.token = token if token is not None else os.environ.get('GITHUB_TOKEN', os.environ.get('GH_TOKEN', ''))
        self.max_requests = max_requests
        self.wait_limit = wait_limit
        self.opener = opener or urllib.request.build_opener(NoRedirect())
        self.sleep, self.clock = sleep, clock
        self.deadline = clock() + minutes * 60
        self.calls = 0
        self.last = {}
        self.blocked_until = 0.0

    def wait(self, delay):
        if delay > self.wait_limit or self.clock() + delay >= self.deadline:
            raise Pause('Пауза API; повторите запуск примерно через %s сек.' % int(delay + 1))
        # Small sleeps also let Ctrl+C interrupt long rate-limit waits.
        while delay > 0:
            step = min(5, delay)
            self.sleep(step)
            delay -= step

    def check_budget(self):
        if self.calls >= self.max_requests or self.clock() >= self.deadline:
            raise Pause('Достигнут бюджет запросов или времени. Прогресс сохранён.')

    def get(self, path, params=None, max_bytes=24_000_000):
        if not path.startswith('/') or path.startswith('//') or '?' in path:
            raise ValueError('Only relative GitHub API paths are accepted')
        bucket = 'code' if path == '/search/code' else 'search' if path.startswith('/search/') else 'core'
        # Authenticated code search: at most 10/min. Anonymous other search: 10/min.
        interval = 6.2 if bucket != 'core' else 0.15
        for attempt in range(4):
            self.check_budget()
            if self.blocked_until > time.time():
                self.wait(self.blocked_until - time.time())
            self.wait(max(0, interval - (self.clock() - self.last.get(bucket, -1000))))
            self.check_budget()
            url = 'https://api.github.com' + path
            if params:
                url += '?' + urllib.parse.urlencode(params)
            headers = {'Accept': 'application/vnd.github+json', 'User-Agent': 'IRLIX-Intelligence/1.0',
                       'X-GitHub-Api-Version': '2026-03-10'}
            if self.token:
                headers['Authorization'] = 'Bearer ' + self.token
            self.calls += 1
            self.last[bucket] = self.clock()
            try:
                with self.opener.open(urllib.request.Request(url, headers=headers), timeout=30) as response:
                    body = response.read(max_bytes + 1)
                    if len(body) > max_bytes:
                        raise APIError(413, 'Ответ API превышает безопасный размер')
                    if response.headers.get('X-RateLimit-Remaining') == '0':
                        self.blocked_until = time.time() + retry_delay(response.headers)
                    return json.loads(body)
            except urllib.error.HTTPError as e:
                detail = e.read(4096).decode('utf-8', 'replace').lower()
                limited = e.code == 429 or (e.code == 403 and (
                    'rate limit' in detail or 'secondary' in detail or
                    e.headers.get('X-RateLimit-Remaining') == '0' or e.headers.get('Retry-After')))
                if limited:
                    delay = retry_delay(e.headers, fallback=60 * 2 ** attempt)
                    self.blocked_until = time.time() + delay
                    if attempt == 3:
                        raise Pause('GitHub ограничил частоту запросов. Очередь сохранена.') from None
                    self.wait(delay)
                elif e.code >= 500 and attempt < 3:
                    self.wait(2 ** (attempt + 1))
                elif e.code >= 500:
                    raise Pause('GitHub временно недоступен; повторите запуск.') from None
                else:
                    # Do not print server bodies: they can contain reflected credentials.
                    raise APIError(e.code, 'GitHub HTTP %d: %s' % (e.code, path)) from None
            except (urllib.error.URLError, TimeoutError, OSError):
                if attempt == 3:
                    raise Pause('Ошибка сети при обращении к GitHub. Очередь сохранена.') from None
                self.wait(2 ** (attempt + 1))
        raise Pause('Не удалось получить ответ GitHub.')

    def metadata(self, repo):
        data = self.get('/repos/' + repo_name(repo))
        # The PAT may access private data; refuse it explicitly, including explicit seeds.
        if data.get('private') is not False or data.get('visibility', 'public') != 'public':
            raise APIError(403, 'Пропущен непубличный репозиторий: ' + repo)
        return data

    def snapshot(self, repo, branch):
        commit = self.get('/repos/' + repo + '/commits/' + urllib.parse.quote(branch, safe=''))
        return commit['sha'], commit['commit']['tree']['sha']

    def tree(self, repo, tree_sha):
        root = '/repos/' + repo + '/git/trees/'
        data = self.get(root + tree_sha, {'recursive': '1'})
        if not data.get('truncated'):
            return data['tree']
        # GitHub's recursive tree can be truncated. Walk individual subtrees instead.
        result, queue = [], [('', tree_sha)]
        while queue:
            prefix, sha = queue.pop()
            subtree = self.get(root + sha)
            if subtree.get('truncated'):
                raise APIError(413, 'Обрезано даже нерекурсивное дерево: ' + repo)
            for item in subtree['tree']:
                path = prefix + item['path']
                if item['type'] == 'tree':
                    queue.append((path + '/', item['sha']))
                elif item['type'] == 'blob':
                    result.append(dict(item, path=path))
        return result

    def blob(self, repo, sha, max_bytes):
        data = self.get('/repos/' + repo + '/git/blobs/' + sha, max_bytes=max_bytes * 2 + 10000)
        if data.get('size', 0) > max_bytes:
            raise APIError(413, 'Документ превышает лимит размера')
        if data.get('encoding') != 'base64':
            raise APIError(415, 'GitHub не вернул содержимое blob')
        result = base64.b64decode(data['content'])
        if len(result) > max_bytes:
            raise APIError(413, 'Документ превышает лимит размера')
        return result

    def search(self, kind, query, page):
        return self.get('/search/' + kind, {'q': query, 'per_page': 100, 'page': page})
