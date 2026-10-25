import csv
import html
import json
import re
from pathlib import Path
from .store import now

COLUMNS = {
 'id': 'ID', 'score': 'Приоритет 0–100', 'grade': 'Класс сигнала', 'category': 'Тип',
 'review': 'Проверка человеком', 'sales_score': 'Коммерческий балл 0–100',
 'relationship_status': 'Тип связи заказчик–подрядчик',
 'sales_lane': 'Канал продаж', 'demand_current_confirmed': 'Текущий спрос подтвержден',
 'customers': 'Заказчик (явно назван)',
 'suppliers': 'Подрядчик (явно назван)', 'entities': 'Упомянутые компании',
 'workplaces': 'Компания в опыте сотрудника',
 'stacks': 'Роли / стеки', 'rates': 'Суммы / ставки', 'years': 'Годы в тексте',
 'contract_numbers': 'Номера договоров', 'document_dates': 'Даты в тексте',
 'payment_terms': 'Условия оплаты', 'legal_entity_inns': 'ИНН юрлиц (контрольная сумма)',
 'geography': 'Признак географии', 'repo': 'Репозиторий', 'known': 'Изучался ранее',
 'path': 'Файл', 'locator': 'Место в документе', 'url': 'Источник',
 'excerpt': 'Цитата', 'limitations': 'Что не доказано', 'next_action': 'Действие селза',
 'captured_at': 'Получено UTC', 'snapshot_commit': 'Commit', 'blob_sha': 'Blob SHA',
 'content_hash': 'SHA256 документа', 'duplicate_of': 'Дубликат содержимого',
 'note': 'Комментарий проверки',
}


def safe_cell(value):
    if isinstance(value, (int, float, bool)):
        return value
    text = str(value if value is not None else '')
    text = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f]', '', text)
    if text.lstrip().startswith(('=', '+', '-', '@')) or text.startswith(('\t', '\r', '\n')):
        text = "'" + text
    return text[:32000]


def rows_for_export(store):
    result, hashes = [], {}
    for row in store.rows('''SELECT f.*,v.path,v.content_hash,r.verdict,r.note FROM findings f
          JOIN files v ON f.file_id=v.id LEFT JOIN reviews r ON r.finding_id=f.id
          ORDER BY f.score DESC,f.captured_at DESC,f.id'''):
        d = json.loads(row['data'])
        signature = (row['content_hash'], row['locator'])
        duplicate = hashes.get(signature, '')
        hashes.setdefault(signature, row['id'])
        output = dict(d, id=row['id'], review=row['verdict'] or 'unreviewed', note=row['note'] or '',
                      path=row['path'], captured_at=row['captured_at'], content_hash=row['content_hash'],
                      duplicate_of=duplicate, known='да' if d.get('previously_reviewed_repo') else 'нет')
        result.append(output)
    return result


def flatten(row):
    return {k: json.dumps(row.get(k), ensure_ascii=False) if isinstance(row.get(k), (dict, list))
            else row.get(k, '') for k in COLUMNS}


def write_csv(path, rows, fields=None, labels=None):
    if fields is None:
        fields = list(rows[0]) if rows else ['status']
    with path.open('w', encoding='utf-8-sig', newline='') as stream:
        writer = csv.writer(stream, delimiter=';')
        writer.writerow([labels.get(k, k) if labels else k for k in fields])
        for row in rows:
            writer.writerow([safe_cell(row.get(k, '')) for k in fields])


def export(store, directory):
    directory.mkdir(parents=True, exist_ok=True)
    rows = rows_for_export(store)
    flat = [flatten(row) for row in rows]
    coverage = store.rows('SELECT repo,path,status,reason,units,processed_at,url,size FROM files ORDER BY repo,path')
    searches = store.rows('SELECT kind,query,page,done,total,incomplete,capped,updated_at FROM search_jobs')
    events = store.rows('SELECT time,level,scope,message FROM events ORDER BY id')
    summary = {'generated_at': now(), 'counts': store.counts(), 'note':
               'Автоматические сигналы, не подтвержденные сделки. Класс A автоматически не присваивается. '
               'Отчёт включает исторические версии документов; дата получения не равна дате спроса.',
               'last_run': store.rows('SELECT * FROM runs ORDER BY id DESC LIMIT 1')}
    (directory / 'findings.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding='utf-8')
    (directory / 'summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
    write_csv(directory / 'findings.csv', flat, list(COLUMNS), COLUMNS)
    # Sales: only named potential buyers, grouped per bounded source claim.
    # B means author's first-hand claim, not an independently verified 2026 contract.
    sales_fields = ['customer','supplier','grade','sales_score','relationship_status','category',
                    'stacks','years','repo','url','locator','next_action','limitations','review']
    by_pair = {}
    for row in rows:
        if row.get('grade') == 'X' or row.get('review') == 'rejected':
            continue
        for customer in row.get('customers') or []:
            for supplier in row.get('suppliers') or ['']:
                signature = (row['repo'], customer.casefold(), supplier.casefold(),row.get('category'))
                values = dict(customer=customer, supplier=supplier, grade=row.get('grade'),
                              sales_score=row.get('sales_score',0), relationship_status=row.get('relationship_status',''),
                              category=row.get('category',''), stacks=', '.join(row.get('stacks',[])),
                              years=', '.join(row.get('years',[])), repo=row['repo'],url=row['url'],
                              locator=row['locator'],next_action=row.get('next_action',''),
                              limitations='; '.join(row.get('limitations',[])),review=row.get('review','unreviewed'))
                if signature not in by_pair or values['sales_score']>by_pair[signature]['sales_score']:
                    by_pair[signature]=values
    buyers = sorted(by_pair.values(),key=lambda x:(-x['sales_score'],x['customer']))
    write_csv(directory / 'sales_buyers.csv', buyers, sales_fields)
    (directory / 'sales_buyers.json').write_text(json.dumps(buyers,ensure_ascii=False,indent=2),encoding='utf-8')
    verified = summary['counts'].get('checkpoint',{}).get('new_verified_repos',0)
    # Signal yield is not a confirmed-sales KPI, so report both raw and reviewed.
    summary['sales_metrics'] = {'buyer_leads':len(buyers),
        'buyer_signals_per_1000_new_verified_repos': round(len(buyers)*1000/verified,2) if verified else None,
        'source_claims_B_with_buyer':sum(x['grade']=='B' for x in buyers),
        'signed_2026_contracts_verified':0}
    (directory / 'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf-8')
    write_csv(directory / 'coverage.csv', coverage)
    write_csv(directory / 'search_coverage.csv', searches)
    write_csv(directory / 'errors.csv', events)
    try:
        from openpyxl import Workbook
        from openpyxl.styles import Font, PatternFill, Alignment
        book = Workbook()
        book.remove(book.active)
        for title, values, fields, labels in [
            ('Находки', flat, list(COLUMNS), COLUMNS),
            ('Покрытие файлов', coverage, list(coverage[0]) if coverage else ['status'], {}),
            ('Поисковые запросы', searches, list(searches[0]) if searches else ['status'], {}),
            ('Журнал', events, list(events[0]) if events else ['status'], {}),
        ]:
            sheet = book.create_sheet(title)
            sheet.append([labels.get(k, k) for k in fields])
            for row in values:
                sheet.append([safe_cell(row.get(k, '')) for k in fields])
            sheet.freeze_panes = 'A2'
            sheet.auto_filter.ref = sheet.dimensions
            for cell in sheet[1]:
                cell.font = Font(bold=True, color='FFFFFF')
                cell.fill = PatternFill('solid', fgColor='8D232E')
            for idx, k in enumerate(fields, 1):
                from openpyxl.utils import get_column_letter
                sheet.column_dimensions[get_column_letter(idx)].width = 60 if k in ('excerpt', 'limitations', 'url', 'message') else 25
            for row in sheet.iter_rows(min_row=2):
                for cell in row:
                    cell.alignment = Alignment(vertical='top', wrap_text=True)
            if 'url' in fields:
                col = fields.index('url') + 1
                for row in sheet.iter_rows(min_row=2):
                    cell = row[col - 1]
                    if str(cell.value or '').startswith('https://github.com/'):
                        cell.hyperlink = cell.value
                        cell.font = Font(color='0563C1', underline='single')
        info = book.create_sheet('Как читать')
        for text in [summary['note'], 'Приоритет 0–100 — сумма правил, а не вероятность покупки.',
                     'B — явное заявление о связи; C — косвенный сигнал; D — общий материал; X — учебный/синтетический материал.',
                     'confirmed_source_claim подтверждает содержание источника, а не подписанный договор.',
                     'pending — ещё не прочитано; partial — прочитано частично; ошибки смотрите в покрытии.',
                     'salary — зарплата; hourly_rate — ставка с указанной единицей; unknown — неизвестно.',
                     'RU_language_only не подтверждает, что компания находится в России.']:
            info.append([text])
        info.column_dimensions['A'].width = 120
        for row in info:
            row[0].alignment = Alignment(wrap_text=True, vertical='top')
        book.save(directory / 'findings.xlsx')
    except ImportError:
        summary['xlsx'] = 'openpyxl not installed; CSV and JSON are available'
        (directory / 'summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
    write_html(directory / 'report.html', rows, summary)
    return summary


def write_html(path, rows, summary):
    esc = html.escape
    cards = []
    # No script interpolation of source data: all untrusted content is escaped HTML.
    for row in rows[:1000]:
        title = ', '.join(row.get('customers') or row.get('entities') or [row['repo']])
        url = row['url'] if row['url'].startswith('https://github.com/') else ''
        link = '<a href="%s" target="_blank" rel="noopener noreferrer">Открыть источник ↗</a>' % esc(url, quote=True) if url else '<span>Локальный документ</span>'
        cards.append('<article data-grade="%s"><div class="meta">%s · %s · %s</div><h2>%s</h2><p>%s</p><blockquote>%s</blockquote><p class="muted">%s</p><footer>%s <span>%s</span></footer></article>' % (
            esc(row['grade']), esc(str(row['score'])), esc(row['grade']), esc(row['category']), esc(title),
            esc(' / '.join(row.get('stacks', []))), esc(row['excerpt']),
            esc(' '.join(row['limitations'])), link, esc(row['locator'])))
    document = '''<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IRLIX · GitHub Intelligence</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f0e7;color:#191919;font:16px/1.5 system-ui,sans-serif}main{max-width:1120px;margin:auto;padding:32px 20px}header{border-bottom:4px solid;padding-bottom:20px}h1{font-size:clamp(28px,6vw,48px);margin:8px 0}h2{font-size:22px;margin:12px 0;overflow-wrap:anywhere}.brand{font-weight:900;color:#9e2833;letter-spacing:2px}.controls{display:flex;gap:12px;margin:24px 0;flex-wrap:wrap}input,select{font:inherit;padding:12px;border:2px solid;background:#fff;max-width:100%}input{flex:1;min-width:180px}article{background:white;border:2px solid;margin:18px 0;padding:20px;box-shadow:4px 4px 0 #222}.meta{font-weight:700;color:#9e2833}blockquote{margin:12px 0;border-left:4px solid #9e2833;padding:8px 16px;white-space:pre-wrap;overflow-wrap:anywhere;background:#f8f5ef}.muted{color:#555;font-size:14px}footer{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:14px}a{color:#932532}#count{font-weight:bold}article[hidden]{display:none}</style><main><header><div class="brand">IRLIX / SALES INTELLIGENCE</div><h1>Коммерческие сигналы GitHub</h1><p>PUBLIC SOURCES · EVIDENCE FIRST</p><p class="muted">__NOTE__</p><p>Находок: __TOTAL__. Файлов в очереди и истории: __FILES__.</p></header>
<div class="controls"><input id="q" placeholder="Компания, стек, текст…" aria-label="Поиск"><select id="grade" aria-label="Класс сигнала"><option value="">Все классы</option><option>B</option><option>C</option><option>D</option><option>X</option></select></div><p id="count"></p><section>__CARDS__</section><p class="muted">HTML показывает первые 1000 находок. Полный набор — в CSV, JSON и XLSX. Исторические версии сохраняются; даты файлов не доказывают текущий спрос.</p></main><script>
const q=document.getElementById('q'),g=document.getElementById('grade'),cards=[...document.querySelectorAll('article')];function filter(){let n=0;for(const c of cards){const ok=(!g.value||c.dataset.grade===g.value)&&c.textContent.toLowerCase().includes(q.value.toLowerCase());c.hidden=!ok;if(ok)n++}document.getElementById('count').textContent='Показано: '+n}q.addEventListener('input',filter);g.addEventListener('change',filter);filter();</script></html>'''
    document = document.replace('__NOTE__', esc(summary['note'])).replace('__TOTAL__', str(len(rows))).replace('__FILES__', str(summary['counts']['files'])).replace('__CARDS__', ''.join(cards) or '<p>Пока нет находок. Проверьте очередь и журнал покрытия.</p>')
    path.write_text(document, encoding='utf-8')
