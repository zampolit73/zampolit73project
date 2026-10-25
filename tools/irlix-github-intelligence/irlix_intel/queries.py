"""REST search syntax; no unsupported browser regex queries."""
import json

TERMS = [
    'аутстафф', 'аутстаффинг', 'outstaff', 'outstaffing', 'isOutstaff',
    '"staff augmentation"', '"предоставление IT-специалистов"',
    '"предоставление ИТ-специалистов"', '"выделенная команда"',
    '"аренда разработчиков"', '"усиление команды"',
]
FOCUS = ['заказчик', 'клиент', 'договор', 'ставка', 'заявка', 'подрядчик',
         'тендер', 'НДС', 'Java', 'React', 'QA', 'DevOps', '1С', 'Python',
         'client', 'customer', 'contract', 'rate']


def default_queries(profile='quick'):
    quick = [('code', word) for word in TERMS[:5]] + [
        ('repositories', 'аутстафф in:name,description,readme'),
        ('repositories', 'outstaff in:name,description,readme'),
        ('issues', 'аутстафф in:title,body is:issue'),
        ('issues', 'outstaff in:title,body is:issue'),
        ('code', '"наши клиенты" аутстафф'),
        ('code', '"заказчик" "разработчиков"'),
        ('code', '"ставка" "аутстафф"'),
    ]
    if profile == 'deep':
        quick += [('code', t) for t in TERMS[5:]]
        quick += [('code', '%s %s' % (term, focus)) for term in ['аутстафф', 'outstaff'] for focus in FOCUS]
        quick += [('code', '%s extension:%s' % (term, ext))
                  for term in ['аутстафф', 'outstaff'] for ext in ['md', 'csv', 'json', 'txt', 'html', 'tsx']]
        quick += [('repositories', q + ' in:name,description,readme') for q in
                  ['"IT аутсорсинг"', '"заказчики" "разработка"', '"тендер" "ИТ"']]
    return [{'kind': kind, 'query': query + ' is:public'} for kind, query in quick]


def load_queries(path, profile):
    result = json.loads(path.read_text(encoding='utf-8')) if path else default_queries(profile)
    for item in result:
        if item['kind'] not in ('code', 'repositories', 'issues') or not item['query'].strip():
            raise ValueError('Invalid query configuration')
    return result
