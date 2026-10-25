import argparse
import json
import sys
from pathlib import Path
from . import __version__
from .exporter import export
from .github import GitHub, APIError, Pause
from .pipeline import Pipeline, read_repos
from .queries import load_queries, default_queries
from .store import Store, now, run_lock

DEFAULT_KNOWN = Path(__file__).resolve().parent.parent / 'config' / 'previously_reviewed.txt'


def positive(value):
    number = int(value)
    if number <= 0:
        raise argparse.ArgumentTypeError('Expected a positive integer')
    return number


def parser():
    root = argparse.ArgumentParser(description='IRLIX: публичные документы GitHub → проверяемые коммерческие сигналы')
    root.add_argument('--version', action='version', version=__version__)
    subs = root.add_subparsers(dest='command', required=True)
    for name, help_text in [('scan', 'Поиск и сканирование GitHub'), ('local', 'Разобрать локальные документы'),
                            ('export', 'Повторная выгрузка отчёта'), ('status', 'Состояние очереди'),
                            ('review', 'Зафиксировать проверку человеком')]:
        p = subs.add_parser(name, help=help_text)
        p.add_argument('--db', type=Path, default=Path('data/intelligence.sqlite'))
        p.add_argument('--out', type=Path, default=Path('reports'))
        if name in ('scan', 'local'):
            p.add_argument('--max-file-mb', type=positive, default=5)
            p.add_argument('--companies', type=Path, help='JSON: canonical company → aliases')
            p.add_argument('--known', type=Path, default=DEFAULT_KNOWN)
            p.add_argument('--retry-errors', action='store_true')
        if name == 'local':
            p.add_argument('folder', type=Path)
        if name == 'scan':
            p.add_argument('--profile', choices=['quick', 'deep'], default='quick')
            p.add_argument('--queries', type=Path)
            p.add_argument('--seeds', type=Path, help='owner/repo, по одному на строку')
            p.add_argument('--exclude', type=Path, help='Дополнительный список исключений')
            p.add_argument('--include-known', action='store_true', help='Разрешить повторный анализ 416 исторических repo без увеличения счётчика')
            p.add_argument('--no-search', action='store_true')
            p.add_argument('--refresh-search', action='store_true', help='Повторить завершённые поиски с первой страницы')
            p.add_argument('--refresh-hours', type=positive, default=24)
            p.add_argument('--max-queries', type=positive, default=12)
            p.add_argument('--search-pages', type=positive, default=1, help='Страниц каждого запроса за запуск; продолжение автоматически')
            p.add_argument('--max-repos', type=positive, default=20)
            p.add_argument('--max-files-per-repo', type=positive, default=100)
            p.add_argument('--max-api-requests', type=positive, default=600)
            p.add_argument('--max-minutes', type=positive, default=30)
            p.add_argument('--max-wait-seconds', type=positive, default=90)
        if name == 'review':
            p.add_argument('id')
            p.add_argument('--verdict', required=True, choices=['confirmed_source_claim', 'needs_followup', 'rejected'])
            p.add_argument('--note', required=True)
    doctor = subs.add_parser('doctor', help='Проверить доступ к API и зависимости')
    doctor.add_argument('--check-code-search', action='store_true', help='Проверить реальный запрос поиска по коду')
    q = subs.add_parser('queries', help='Показать редактируемый набор запросов')
    q.add_argument('--profile', choices=['quick', 'deep'], default='quick')
    return root


def doctor(args):
    import importlib.util
    client = GitHub(max_requests=4, minutes=2, wait_limit=10)
    print('Python:', sys.version.split()[0], '| GITHUB_TOKEN:', 'задан' if client.token else 'не задан')
    for name in ('pypdf', 'docx', 'openpyxl', 'xlrd'):
        print(name + ':', 'OK' if importlib.util.find_spec(name) else 'не установлен')
    data = client.get('/rate_limit')
    for name in ('core', 'search', 'code_search'):
        item = data.get('resources', {}).get(name, {})
        print('%s: remaining=%s / limit=%s' % (name, item.get('remaining'), item.get('limit')))
    if args.check_code_search:
        if not client.token:
            print('Поиск по коду требует GitHub token. Токен вводится только локально.')
            return 2
        response = client.search('code', 'outstaff is:public', 1)
        print('Поиск по коду OK; найдено:', response.get('total_count'))
    return 0


def main(argv=None):
    args = parser().parse_args(argv)
    try:
        if args.command == 'queries':
            print(json.dumps(default_queries(args.profile), ensure_ascii=False, indent=2))
            return 0
        if args.command == 'doctor':
            return doctor(args)
        with run_lock(args.db.with_suffix('.lock')):
            store = Store(args.db)
            try:
                if args.command == 'status':
                    store.init_progress(known_count=416)
                    print(json.dumps(store.counts(), ensure_ascii=False, indent=2))
                    return 0
                if args.command == 'export':
                    export(store, args.out)
                    print('Отчёт:', args.out.resolve())
                    return 0
                if args.command == 'review':
                    if not store.rows('SELECT id FROM findings WHERE id=?', (args.id,)):
                        raise ValueError('Находка с таким ID не существует')
                    store.execute('INSERT OR REPLACE INTO reviews VALUES(?,?,?,?)', (args.id, args.verdict, args.note, now()))
                    export(store, args.out)
                    return 0
                known = read_repos(args.known) if args.known and args.known.exists() else set()
                aliases = json.loads(args.companies.read_text(encoding='utf-8')) if args.companies else {}
                if any(not isinstance(v, list) or not all(isinstance(s, str) for s in v) for v in aliases.values()):
                    raise ValueError('companies должен содержать списки строк')
                # Default: historical 416 are excluded, not merely tagged.
                excluded = read_repos(args.exclude) if args.command == 'scan' else set()
                if args.command == 'scan' and not args.include_known:
                    excluded |= known
                store.init_progress(known_count=len(known))
                if args.retry_errors:
                    store.execute("UPDATE files SET status='pending' WHERE status IN ('fetch_error','parse_error','parse_timeout','missing_dependency')")
                api = GitHub(max_requests=args.max_api_requests, minutes=args.max_minutes, wait_limit=args.max_wait_seconds) if args.command == 'scan' else None
                pipeline = Pipeline(store, api, args, known, excluded, aliases)
                run_id = store.execute('INSERT INTO runs(started_at,status) VALUES(?,?)', (now(), 'running')).lastrowid
                status, exit_code = 'completed_budgeted_pass', 0
                try:
                    if args.command == 'local':
                        if not args.folder.is_dir():
                            raise ValueError('Папка не существует: ' + str(args.folder))
                        pipeline.local(args.folder)
                    else:
                        for repo in read_repos(args.seeds):
                            pipeline.accept_repo(repo)
                        if args.refresh_search:
                            store.execute('UPDATE search_jobs SET done=0,page=1,incomplete=0,capped=0')
                        if not api.token:
                            print('Без токена: доступен обход публичных репозиториев и поиск репозиториев/issues. Поиск по коду пропущен.', flush=True)
                        # Work already queued gets first priority, so discovery cannot starve downloads.
                        pipeline.scan_repos()
                        if not args.no_search:
                            pipeline.discover(load_queries(args.queries, args.profile))
                        remaining = args.max_repos - pipeline.stats['repos_scanned']
                        if remaining > 0:
                            args.max_repos = remaining
                            pipeline.scan_repos()
                except (Pause, KeyboardInterrupt) as e:
                    status, exit_code = 'paused', 2
                    message = str(e) or 'Остановлено пользователем. Прогресс сохранён.'
                    store.event('warning', 'run', message)
                    print(message, flush=True)
                except Exception as e:
                    status, exit_code = 'error', 1
                    store.event('error', 'run', type(e).__name__ + ': ' + str(e))
                    print('Ошибка:', str(e), file=sys.stderr)
                finally:
                    if api:
                        pipeline.stats['api_requests'] = api.calls
                    store.execute('UPDATE runs SET ended_at=?,status=?,stats=? WHERE id=?',
                                  (now(), status, json.dumps(pipeline.stats), run_id))
                    summary = export(store, args.out)
                    print(json.dumps(pipeline.stats, ensure_ascii=False))
                    print('Состояние:', status, '| отчёт:', args.out.resolve())
                    print('Всего в базе:', json.dumps(summary['counts'], ensure_ascii=False))
                return exit_code
            finally:
                store.close()
    except (APIError, Pause, ValueError, RuntimeError, OSError) as e:
        print('Ошибка:', str(e), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
