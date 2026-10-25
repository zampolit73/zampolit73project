import contextlib
import hashlib
import json
import sqlite3
from pathlib import Path
from datetime import datetime, timezone


def now():
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def key(*parts):
    return hashlib.sha256('\x1f'.join(str(x) for x in parts).encode()).hexdigest()


class Store:
    def __init__(self, path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(path)
        self.db.row_factory = sqlite3.Row
        self.db.executescript('''
        PRAGMA journal_mode=WAL;
        CREATE TABLE IF NOT EXISTS repos (
          repo TEXT PRIMARY KEY, status TEXT DEFAULT 'pending', commit_sha TEXT,
          tree_sha TEXT, checked_at TEXT, scanned_at TEXT, known INTEGER DEFAULT 0,
          meta TEXT, discovered_at TEXT, error TEXT);
        CREATE TABLE IF NOT EXISTS search_jobs (
          id TEXT PRIMARY KEY, kind TEXT, query TEXT, page INTEGER DEFAULT 1,
          done INTEGER DEFAULT 0, total INTEGER, incomplete INTEGER DEFAULT 0,
          capped INTEGER DEFAULT 0, updated_at TEXT);
        CREATE TABLE IF NOT EXISTS hits (repo TEXT, path TEXT, PRIMARY KEY(repo,path));
        CREATE TABLE IF NOT EXISTS files (
          id TEXT PRIMARY KEY, repo TEXT, path TEXT, sha TEXT, commit_sha TEXT,
          url TEXT, size INTEGER, priority INTEGER, status TEXT DEFAULT 'pending',
          reason TEXT, processed_at TEXT, content_hash TEXT, units INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS findings (
          id TEXT PRIMARY KEY, file_id TEXT, repo TEXT, url TEXT, locator TEXT,
          score INTEGER, grade TEXT, category TEXT, data TEXT, captured_at TEXT);
        CREATE TABLE IF NOT EXISTS reviews (
          finding_id TEXT PRIMARY KEY, verdict TEXT, note TEXT, reviewed_at TEXT);
        CREATE TABLE IF NOT EXISTS events (
          id INTEGER PRIMARY KEY, time TEXT, level TEXT, scope TEXT, message TEXT);
        CREATE TABLE IF NOT EXISTS runs (
          id INTEGER PRIMARY KEY, started_at TEXT, ended_at TEXT, status TEXT,
          stats TEXT);
        CREATE TABLE IF NOT EXISTS progress (
          id INTEGER PRIMARY KEY CHECK(id=1), last_pass INTEGER NOT NULL DEFAULT 394,
          initial_base INTEGER NOT NULL DEFAULT 65, goal INTEGER NOT NULL DEFAULT 50000,
          known_repo_count INTEGER NOT NULL DEFAULT 416);
        CREATE TABLE IF NOT EXISTS verified_repos (
          repo TEXT PRIMARY KEY, pass_number INTEGER UNIQUE NOT NULL,
          first_file_id TEXT NOT NULL, verified_at TEXT NOT NULL,
          FOREIGN KEY(first_file_id) REFERENCES files(id));
        ''')
        self.db.commit()
        self.init_progress()  # also supports direct Store/Pipeline usage

    def execute(self, sql, params=()):
        with self.db:
            return self.db.execute(sql, params)

    def rows(self, sql, params=()):
        return [dict(x) for x in self.db.execute(sql, params)]

    def event(self, level, scope, message):
        self.execute('INSERT INTO events(time,level,scope,message) VALUES(?,?,?,?)',
                     (now(), level, scope, message))

    def add_repo(self, repo, known=False):
        self.execute('INSERT OR IGNORE INTO repos(repo,known,discovered_at) VALUES(?,?,?)',
                     (repo.lower(), int(known), now()))
        if known:
            self.execute('UPDATE repos SET known=1 WHERE repo=?', (repo.lower(),))

    def add_file(self, repo, path, sha, commit, url, size, priority=0, status='pending', reason=None):
        ident = key(repo.lower(), path, sha)
        self.execute('''INSERT OR IGNORE INTO files
          (id,repo,path,sha,commit_sha,url,size,priority,status,reason) VALUES(?,?,?,?,?,?,?,?,?,?)''',
          (ident, repo.lower(), path, sha, commit, url, size, priority, status, reason))
        return ident

    def save_findings(self, file_id, findings, status, reason, content_hash, units):
        # One transaction: interruption cannot mark a file done without its evidence.
        with self.db:
            self.db.execute('DELETE FROM findings WHERE file_id=?', (file_id,))
            for f in findings:
                ident = key(file_id, f['locator'])
                self.db.execute('''INSERT INTO findings
                  (id,file_id,repo,url,locator,score,grade,category,data,captured_at)
                  VALUES(?,?,?,?,?,?,?,?,?,?)''',
                  (ident, file_id, f['repo'], f['url'], f['locator'], f['score'],
                   f['grade'], f['category'], json.dumps(f, ensure_ascii=False), now()))
            self.db.execute('''UPDATE files SET status=?,reason=?,processed_at=?,content_hash=?,units=?
                               WHERE id=?''', (status, reason, now(), content_hash, units, file_id))

    def init_progress(self, known_count=416, last_pass=394):
        """Only initializes an empty DB; never resets an existing checkpoint."""
        with self.db:
            self.db.execute('INSERT OR IGNORE INTO progress(id,last_pass,known_repo_count) VALUES (1,?,?)',
                            (last_pass,known_count))
            self.db.execute('UPDATE progress SET known_repo_count=MAX(known_repo_count,?) WHERE id=1',
                            (known_count,))

    def verified(self, repo, file_id):
        """Atomic numbering after parsing a genuinely opened file; never count search snippets."""
        normalized = repo.lower()
        if normalized == 'local/import':
            return None
        with self.db:
            found = self.db.execute('SELECT pass_number FROM verified_repos WHERE repo=?', (normalized,)).fetchone()
            if found:
                return found['pass_number']
            row = self.db.execute('SELECT status FROM files WHERE id=? AND repo=?', (file_id,normalized)).fetchone()
            if not row or row['status'] not in ('done','no_signal'):
                return None
            state = self.db.execute('SELECT last_pass FROM progress WHERE id=1').fetchone()
            if not state:
                raise RuntimeError('Progress not initialized')
            number = state['last_pass']+1
            self.db.execute('UPDATE progress SET last_pass=? WHERE id=1', (number,))
            self.db.execute('INSERT INTO verified_repos(repo,pass_number,first_file_id,verified_at) VALUES(?,?,?,?)',
                            (normalized,number,file_id,now()))
            return number

    def progress_stats(self):
        row = self.rows('SELECT * FROM progress WHERE id=1')
        if not row:
            return {}
        state = row[0]
        new = self.db.execute('SELECT COUNT(*) FROM verified_repos').fetchone()[0]
        additional = state['last_pass'] - state['initial_base']
        return dict(last_pass=state['last_pass'],next_pass=state['last_pass']+1,
                    additional_checks=additional,remaining=max(0,state['goal']-additional),
                    original_unique_repos=state['known_repo_count'],new_verified_repos=new,
                    unique_repos_total=state['known_repo_count']+new)

    def counts(self):
        result = {}
        for table in ('repos', 'files', 'findings'):
            result[table] = self.db.execute('SELECT count(*) FROM ' + table).fetchone()[0]
        result['file_statuses'] = {r['status']: r['n'] for r in self.rows(
            'SELECT status,count(*) n FROM files GROUP BY status')}
        result['search_incomplete_or_capped'] = self.db.execute(
            'SELECT count(*) FROM search_jobs WHERE capped=1 OR incomplete=1').fetchone()[0]
        result['checkpoint'] = self.progress_stats()
        return result

    def close(self):
        self.db.close()


@contextlib.contextmanager
def run_lock(path):
    """OS lock released on process death; works on Windows and Linux."""
    path.parent.mkdir(parents=True, exist_ok=True)
    stream = path.open('a+b')
    stream.seek(0)
    if stream.read(1) == b'':
        stream.write(b'0')
        stream.flush()
    stream.seek(0)
    try:
        if __import__('os').name == 'nt':
            import msvcrt
            msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        stream.close()
        raise RuntimeError('С этой базой уже работает другой процесс.') from None
    try:
        yield
    finally:
        stream.close()
