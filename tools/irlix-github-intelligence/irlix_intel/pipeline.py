import hashlib
import json
import re
import subprocess
import sys
import tempfile
import urllib.parse
from datetime import datetime, timedelta, timezone
from pathlib import Path
from .analyze import analyze
from .sales import profile_units, score_lead
from .extract import Unit, TEXT_EXT, DOC_EXT, UNSUPPORTED
from .github import APIError, Pause, repo_name
from .store import key, now

INTEREST = re.compile(r'outstaff|аутста|contract|договор|клиент|customer|client|rate|ставк|vacanc|ваканс|заявк|tender|тендер|price|прайс|portfolio|кейс|case|requisit|реквиз|purchase|закуп|resume|experience', re.I)
IGNORE_DIR = {'node_modules', '.git', 'vendor', 'dist', 'build', '.venv', 'venv', '__pycache__'}
SECRET_PATH = re.compile(r'(^|/)(?:\.env(?:\..*)?|id_rsa|id_ed25519|credentials(?:\..*)?|secrets(?:\..*)?)$|\.(?:pem|key|p12|pfx)$', re.I)


def read_repos(path):
    if not path:
        return set()
    return {repo_name(line.split('#')[0].strip()) for line in path.read_text(encoding='utf-8-sig').splitlines()
            if line.split('#')[0].strip()}


def candidate(path, hits):
    parts = Path(path).parts
    if set(parts) & IGNORE_DIR or SECRET_PATH.search(path):
        return None
    if re.search(r'\.(?:css|scss|sass)(?:\.[jt]s)?$', path, re.I):
        return None
    ext = Path(path).suffix.lower()
    if path in hits:
        return 100
    if ext in DOC_EXT:
        return 80 + 10 * bool(INTEREST.search(path))
    if ext in UNSUPPORTED:
        return 60 if INTEREST.search(path) else None
    if ext in TEXT_EXT:
        if INTEREST.search(path):
            return 90 if set(parts) & {'data', 'constants', 'content'} else 75
        if Path(path).name.lower().startswith('readme'):
            return 70
        if ext in ('.md', '.txt', '.csv', '.tsv', '.json', '.html'):
            return 30
    return None


def safe_extract(data, filename):
    with tempfile.TemporaryDirectory(prefix='irlix_parse_') as temp:
        input_path, result_path = Path(temp) / 'input', Path(temp) / 'output.json'
        input_path.write_bytes(data)
        try:
            result = subprocess.run([sys.executable, '-m', 'irlix_intel.extract_worker', str(input_path),
                                     str(result_path), filename], stdout=subprocess.DEVNULL,
                                    stderr=subprocess.DEVNULL, timeout=45)
            if result.returncode or not result_path.exists():
                return {'units': [], 'warnings': ['extractor_process_failed'], 'status': 'parse_error'}
            return json.loads(result_path.read_text(encoding='utf-8'))
        except subprocess.TimeoutExpired:
            return {'units': [], 'warnings': ['extractor_timeout:45s'], 'status': 'parse_timeout'}


class Pipeline:
    def __init__(self, store, api, args, known=None, excluded=None, aliases=None):
        self.store, self.api, self.args = store, api, args
        self.known, self.excluded, self.aliases = known or set(), excluded or set(), aliases or {}
        self.checked_public = set()
        self.stats = {'search_pages': 0, 'repos_scanned': 0, 'new_verified_repos': 0, 'files_processed': 0, 'new_findings': 0}

    def accept_repo(self, name):
        name = repo_name(name)
        if name in self.excluded:
            return False
        self.store.add_repo(name, name in self.known)
        return True

    def public_repo(self, name):
        if name in self.checked_public:
            return None
        meta = self.api.metadata(name)
        self.checked_public.add(name)
        return meta

    def process(self, f, data):
        parsed = safe_extract(data, f['path'])
        source = {'repo': f['repo'], 'path': f['path'], 'url': f['url'], 'blob_sha': f['sha'],
                  'snapshot_commit': f['commit_sha'], 'previously_reviewed_repo': f['repo'] in self.known}
        findings = []
        # Structured career records may be split across separate generic text chunks.
        # Use bounded exact-source spans so an employer and a claim never cross projects.
        structured = profile_units(data, f['path']) if f['path'].lower().endswith(('.ts', '.tsx', '.js', '.jsx', '.htm', '.html')) else []
        for obj in parsed['units'] + structured:
            result = analyze(Unit(text=obj['text'], locator=obj['locator']), source, self.aliases)
            if result:
                if obj.get('profile_customer'):
                    customer = obj['profile_customer']
                    result['customers'] = [customer]
                    if obj.get('profile_supplier'):
                        result['suppliers'] = [obj['profile_supplier']]
                    result['category'] = 'employee_outstaff_customer_claim'
                    result['grade'] = 'B'
                    result['explicit_pair'] = False
                    if obj.get('profile_supplier'):
                        result['category'] = 'employee_named_outstaff_pair_claim'
                        result['limitations'].append('Пара следует из одной записи публичного портфолио; договор не прочитан и не подтверждён.')
                    result['limitations'].append('Заказчик указан в одной записи CV/портфолио; подлинность и текущая закупка не проверены.')
                    result['next_action'] = 'Проверить текущие закупки внешних IT-разработчиков у ' + customer + '; связи требуют независимой проверки.'
                result.update(score_lead(result))
                # Stable commit permalink, plus line anchor for plain-text files.
                match = re.fullmatch(r'lines:(\d+)-(\d+)', obj['locator'])
                if match and '/blob/' in result['url']:
                    result['url'] += '#L%s-L%s' % match.groups()
                findings.append(result)
        status = parsed['status']
        if status == 'ok':
            status = 'done' if findings else 'no_signal'
        self.store.save_findings(f['id'], findings, status, '; '.join(parsed['warnings']),
                                 hashlib.sha256(data).hexdigest(), len(parsed['units']))
        self.stats['files_processed'] += 1
        self.stats['new_findings'] += len(findings)
        if status in ('done','no_signal') and f['repo'] not in self.excluded and f['repo'] not in self.known:
            already = bool(self.store.rows('SELECT 1 FROM verified_repos WHERE repo=?', (f['repo'],)))
            number = self.store.verified(f['repo'], f['id'])
            if number and not already:
                self.stats['new_verified_repos'] += 1
                self.store.event('info', f['repo'], 'Новый проверенный репозиторий, прогон №%s' % number)

    def discover(self, queries):
        # Include durable child partitions created on earlier passes.
        configured = {(q['kind'], q['query']) for q in queries}
        queries = list(queries) + [{'kind': r['kind'], 'query': r['query']} for r in
            self.store.rows('SELECT kind,query FROM search_jobs WHERE done=0 ORDER BY rowid')
            if (r['kind'], r['query']) not in configured]
        selected = 0
        for q in queries:
            if selected >= self.args.max_queries:
                break
            kind, query = q['kind'], q['query']
            ident = key(kind, query)
            self.store.execute('INSERT OR IGNORE INTO search_jobs(id,kind,query) VALUES(?,?,?)', (ident, kind, query))
            job = self.store.rows('SELECT * FROM search_jobs WHERE id=?', (ident,))[0]
            if job['done']:
                continue
            if kind == 'code' and not self.api.token:
                self.store.event('warning', query, 'code_search_requires_GITHUB_TOKEN; job remains pending')
                continue
            selected += 1
            page = job['page']
            pages_this_run = 0
            while page <= 10 and pages_this_run < self.args.search_pages:
                print('Поиск [%s], страница %d: %s' % (kind, page, query), flush=True)
                try:
                    response = self.api.search(kind, query, page)
                except APIError as e:
                    self.store.event('error', query, str(e))
                    if e.status == 401:
                        raise Pause('GitHub отклонил токен. Проверьте GITHUB_TOKEN.') from None
                    # Query errors remain visible and can be retried after config changes.
                    break
                items = response.get('items', [])
                for item in items:
                    if kind == 'repositories':
                        if item.get('private') is False:
                            self.accept_repo(item['full_name'])
                    elif kind == 'code':
                        repo = item['repository']
                        if repo.get('private') is False and self.accept_repo(repo['full_name']):
                            self.store.execute('INSERT OR IGNORE INTO hits(repo,path) VALUES(?,?)',
                                               (repo['full_name'].lower(), item['path']))
                    else:
                        repo = item.get('repository_url', '').removeprefix('https://api.github.com/repos/')
                        try:
                            if not self.accept_repo(repo):
                                continue
                            self.public_repo(repo)
                        except (APIError, ValueError) as e:
                            self.store.event('warning', repo, str(e))
                            continue
                        # Fetch body itself; a search excerpt does not count as read evidence.
                        issue = self.api.get('/repos/%s/issues/%d' % (repo, item['number']))
                        content = (issue.get('title', '') + '\n\n' + (issue.get('body') or '')).encode()
                        sha = hashlib.sha256(content).hexdigest()
                        path = 'issues/%d.md' % issue['number']
                        ident_file = self.store.add_file(repo, path, sha, None, issue['html_url'], len(content))
                        f = self.store.rows('SELECT * FROM files WHERE id=?', (ident_file,))[0]
                        if f['status'] == 'pending':
                            self.process(f, content)
                total = response.get('total_count', 0)
                incomplete = bool(response.get('incomplete_results'))
                done = not incomplete and (len(items) < 100 or page * 100 >= min(total, 1000))
                capped = total > 1000
                partitioned = False
                if capped and not incomplete and kind == 'code':
                    match = re.search(r'\bsize:(\d+)\.\.(\d+)\b', query)
                    low, high = (int(match[1]), int(match[2])) if match else (0, 393215)
                    if low < high and (match or 'size:' not in query):
                        base = query[:match.start()] + query[match.end():] if match else query
                        middle = (low + high) // 2
                        for a, b in ((low, middle), (middle + 1, high)):
                            child = base.strip() + ' size:%d..%d' % (a, b)
                            self.store.execute('INSERT OR IGNORE INTO search_jobs(id,kind,query) VALUES(?,?,?)',
                                               (key(kind, child), kind, child))
                        partitioned = True
                        done = True
                        self.store.event('info', query, 'Saturated code query partitioned by file size; child queries remain queued')
                self.store.execute('''UPDATE search_jobs SET page=?,done=?,total=?,incomplete=?,capped=?,updated_at=? WHERE id=?''',
                                   (page if incomplete else page + 1, int(done), total, int(incomplete), int(capped), now(), ident))
                self.stats['search_pages'] += 1
                pages_this_run += 1
                if incomplete:
                    self.store.event('warning', query, 'GitHub returned incomplete_results; page remains pending')
                    break
                if capped and not partitioned:
                    self.store.event('warning', query, 'Search capped at 1000; narrow queries in config')
                if done:
                    break
                page += 1

    def prepare_repo(self, row):
        repo = row['repo']
        meta = self.public_repo(repo) or self.api.metadata(repo)
        commit, tree = self.api.snapshot(repo, meta['default_branch'])
        self.store.execute('UPDATE repos SET meta=? WHERE repo=?',
                           (json.dumps({k: meta.get(k) for k in ('html_url', 'default_branch', 'pushed_at', 'archived', 'fork', 'description')}, ensure_ascii=False), repo))
        if commit == row.get('commit_sha') and row.get('status') in ('done', 'partial'):
            # No content changes. Error/unsupported records remain in the coverage export.
            self.store.execute('UPDATE repos SET scanned_at=?,checked_at=? WHERE repo=?', (now(), now(), repo))
            return
        hits = {h['path'] for h in self.store.rows('SELECT path FROM hits WHERE repo=?', (repo,))}
        for item in self.api.tree(repo, tree):
            if item.get('type') != 'blob' or item.get('mode') == '120000':
                continue
            priority = candidate(item['path'], hits)
            if priority is None:
                continue
            ext = Path(item['path']).suffix.lower()
            status = 'unsupported' if ext in UNSUPPORTED else 'oversize' if item.get('size', 0) > self.args.max_file_mb * 1024 ** 2 else 'pending'
            url = 'https://github.com/%s/blob/%s/%s' % (repo, commit, urllib.parse.quote(item['path'], safe='/'))
            self.store.add_file(repo, item['path'], item['sha'], commit, url, item.get('size', 0), priority, status,
                                'requires_conversion_or_OCR' if status == 'unsupported' else 'size_limit' if status == 'oversize' else None)
        self.store.execute("UPDATE repos SET status='queued',scanned_at=?,checked_at=?,commit_sha=?,tree_sha=?,error=NULL WHERE repo=?", (now(), now(), commit, tree, repo))

    def scan_repos(self):
        cutoff = (datetime.now(timezone.utc) - timedelta(hours=self.args.refresh_hours)).isoformat(timespec='seconds')
        rows = self.store.rows('''SELECT * FROM repos WHERE status IN ('pending','queued','error')
                                 OR checked_at IS NULL OR checked_at < ? ORDER BY
                                 CASE status WHEN 'queued' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,
                                 discovered_at LIMIT ?''', (cutoff, self.args.max_repos))
        for row in rows:
            repo = row['repo']
            if repo in self.excluded:
                continue
            print('Репозиторий: ' + repo, flush=True)
            try:
                if row['status'] != 'queued':
                    self.prepare_repo(row)
                else:
                    # A repository can become private after discovery; recheck on every run.
                    self.public_repo(repo)
                files = self.store.rows("SELECT * FROM files WHERE repo=? AND status='pending' AND path NOT LIKE 'issues/%' ORDER BY priority DESC,path LIMIT ?",
                                        (repo, self.args.max_files_per_repo))
                for f in files:
                    try:
                        data = self.api.blob(repo, f['sha'], self.args.max_file_mb * 1024 ** 2)
                        self.process(f, data)
                    except APIError as e:
                        self.store.execute("UPDATE files SET status='fetch_error',reason=?,processed_at=? WHERE id=?", (str(e), now(), f['id']))
                        self.store.event('error', f['url'], str(e))
                        if e.status == 401:
                            raise Pause('GitHub отклонил токен.') from None
                pending = self.store.rows("SELECT count(*) n FROM files WHERE repo=? AND status='pending'", (repo,))[0]['n']
                errors = self.store.rows("SELECT count(*) n FROM files WHERE repo=? AND status NOT IN ('pending','done','no_signal')", (repo,))[0]['n']
                status = 'queued' if pending else 'partial' if errors else 'done'
                self.store.execute('UPDATE repos SET status=?,scanned_at=? WHERE repo=?', (status, now(), repo))
                # Discovery of empty repositories does not count toward verified passes.
                self.stats['repos_scanned'] += 1
            except APIError as e:
                self.store.execute("UPDATE repos SET status='error',error=?,checked_at=? WHERE repo=?", (str(e), now(), repo))
                self.store.event('error', repo, str(e))
                if e.status == 401:
                    raise Pause('GitHub отклонил токен.') from None

    def local(self, folder):
        for path in sorted(folder.rglob('*')):
            if not path.is_file() or path.is_symlink():
                continue
            if path.suffix.lower() not in TEXT_EXT | DOC_EXT | UNSUPPORTED:
                continue
            if path.stat().st_size > self.args.max_file_mb * 1024 ** 2:
                self.store.event('warning', str(path), 'local_file_oversize')
                continue
            data = path.read_bytes()
            sha = hashlib.sha256(data).hexdigest()
            rel = path.relative_to(folder).as_posix()
            ident = self.store.add_file('local/import', rel, sha, None, '', len(data))
            f = self.store.rows('SELECT * FROM files WHERE id=?', (ident,))[0]
            if f['status'] == 'pending':
                self.process(f, data)
