"""Conservative evidence extraction. Priority is a rule score, NOT probability."""
import re
import urllib.parse
from datetime import date

OUTSTAFF = re.compile(r'аутста[ф]+|\boutstaff(?:ing)?\b|staff\s+augmentation|\bisOutstaff\s*[:=]\s*true\b|аренд[аы]\s+(?:IT[- ]|ИТ[- ])?(?:разработчик|специалист)|предоставлени[ея]\s+(?:IT[- ]|ИТ[- ])специалист|выделенн\w*\s+команд', re.I)
NEGATIVE = re.compile(r'(?:не\s+(?:используем|работаем|предлагаем|оказываем|применяем)|без|отказ\w*\s+от)\s+.{0,25}(?:аутстафф|outstaff)|\bне\s+аутстафф|\bnot\s+outstaff|isOutstaff\s*[:=]\s*false', re.I)
LEGAL = re.compile(r'\b(?:ООО|ПАО|НАО|АО|ОАО|ЗАО)\s*[«"“][^»"”\n]{2,100}[»"”]')
MONEY = re.compile(r'(?<![\w.])(?P<a>\d{1,3}(?:[ \u00a0]\d{3})+|\d{2,9})(?:[.,](?P<dec>\d{1,2}))?(?:\s*[-–—]\s*(?P<b>\d{1,3}(?:[ \u00a0]\d{3})+|\d{2,9}))?\s*(?P<mult>тыс\.?|[kк])?\s*(?P<currency>руб(?:лей|ля|ль)?\.?|₽|RUB|USD|EUR|\$|€)(?:\s*(?:/|за|в)\s*(?P<unit>ч\b|час\w*|hour\w*|h\b|мес\w*|month\w*|день|дня|day\w*))?', re.I)
STACKS = {
    'Java': r'\bjava\b', '.NET/C#': r'(?<!\w)c#|\.net\b', 'Python': r'\bpython\b',
    'Go': r'\bgolang\b|(?<!\w)Go(?!\w)', 'PHP': r'\bphp\b', 'React': r'\breact\b',
    'Vue': r'\bvue\b', 'Angular': r'\bangular\b', 'QA': r'\bqa\b|тестиров\w*',
    'Аналитика': r'аналитик\w*|business analyst|system analyst', 'DevOps': r'devops|\bsre\b',
    'Android': r'android|kotlin', 'iOS': r'\bios\b|swift', '1C': r'(?<!\w)1[сc](?!\w)',
    'Дизайн': r'дизайн\w*|\bux\b|\bui\b',
}
NOISE = re.compile(r'синтетическ|учебн\w*\s+(?:пример|проект|задан)|вымышлен|демонстрационн|mock data|dummy data|synthetic|fictional', re.I)
DEMAND = re.compile(r'требуетс[яь]|ищем|ваканси|заявк[аиу]|нуж(?:ен|на|ны)|закупк|тендер|запрос\s+на|hiring|looking for', re.I)


def redact(text):
    text = re.sub(r'-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----', '[REDACTED_KEY]', text)
    text = re.sub(r'\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,})\b', '[REDACTED_TOKEN]', text)
    text = re.sub(r'(?i)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b', '[EMAIL_REMOVED]', text)
    text = re.sub(r'(?<!\w)(?:\+7|8)[\s(.-]*\d{3}[\s).\-]*\d{3}[\s.-]*\d{2}[\s.-]*\d{2}(?!\d)', '[PHONE_REMOVED]', text)
    return text


def role_names(text, role):
    labels = {'customer': r'заказчик|конечный клиент|клиент|customer|client',
              'supplier': r'исполнитель|подрядчик|поставщик|supplier|contractor'}[role]
    values = []
    pattern = r'(?im)(?:^|[;|\n])[\t ]*(?:' + labels + r')[\t ]*[:=–—][\t ]*([^\n;|]{1,140})'
    for m in re.finditer(pattern, text):
        value = re.split(r',\s*(?:ИНН|именуем|в лице)|\s+\b(?:ставка|договор|проект)\s*:', m[1], flags=re.I)[0].strip(' ,.:"')
        if re.search(r'не\s+(?:указан|известен|раскрыт)|анонимн|NDA|confidential|unknown', value, re.I):
            continue
        if value and len(value) <= 100:
            values.append(value)
    # Contract form: ООО «Name», именуемое в дальнейшем «Заказчик».
    pattern = r'(' + LEGAL.pattern + r')[,\t ]+именуем\w*[\t ]+(?:в дальнейшем[\t ]+)?[«"“]?(?:' + labels + r')[»"”]?'
    values += [m[1] for m in re.finditer(pattern, text, re.I)]
    return list(dict.fromkeys(values))


def money_values(text):
    found = []
    spans = []
    for m in MONEY.finditer(text):
        spans.append(m.span())
        def number(s):
            return float(s.replace(' ', '').replace('\u00a0', ''))
        multiplier = 1000 if m['mult'] else 1
        amount = (number(m['a']) + (float('0.' + m['dec']) if m['dec'] else 0)) * multiplier
        high = number(m['b']) * multiplier if m['b'] else None
        unit_raw = (m['unit'] or '').lower()
        context = text[max(0, m.start() - 100):min(len(text), m.end() + 100)]
        # The clause around this money value, not another row's salary/tax.
        left = max(text.rfind('|', 0, m.start()), text.rfind(';', 0, m.start()), text.rfind('\n', 0, m.start()))
        right_candidates = [p for p in (text.find('|', m.end()), text.find(';', m.end()), text.find('\n', m.end())) if p >= 0]
        clause = text[left + 1:min(right_candidates) if right_candidates else len(text)]
        unit = 'hour' if re.match(r'ч|hour|h$', unit_raw) else 'month' if re.match(r'мес|month', unit_raw) else 'day' if unit_raw else 'unknown'
        kind = 'unknown_amount'
        if re.search(r'зарплат|оклад|з/п|salary|на руки|gross|net\b', clause, re.I):
            kind = 'salary'
        elif re.search(r'сумма\s+договор|стоимость\s+договор|цена\s+договор|бюджет|НМЦ', clause, re.I):
            kind = 'contract_total_or_budget'
        elif re.search(r'ставк|rate|тариф|стоимость\s+час', clause, re.I) or unit == 'hour':
            kind = 'hourly_rate' if unit == 'hour' else 'rate_unknown_unit'
        tax = 'unknown'
        if re.search(r'без\s+НДС|НДС\s+не\s+облага', clause, re.I):
            tax = 'without_VAT'
        elif re.search(r'с\s+(?:уч[её]том\s+)?НДС|включая\s+НДС', clause, re.I):
            tax = 'including_VAT'
        currency = 'USD' if m['currency'].upper() in ('USD', '$') else 'EUR' if m['currency'].upper() in ('EUR', '€') else 'RUB'
        found.append({'min': amount, 'max': high, 'currency': currency, 'unit': unit,
                      'kind': kind, 'VAT': tax, 'quote': redact(context)})
    # Rate cards often omit a currency or unit. Preserve ambiguity instead of assuming RUB/h.
    for m in re.finditer(r'(?:ставка|rate)\s*[:=]\s*(\d{1,3}(?:[ \u00a0]\d{3})+|\d{2,7})(?!\d)', text, re.I):
        if any(a <= m.start(1) < b for a, b in spans):
            continue
        found.append({'min': float(m[1].replace(' ', '').replace('\u00a0', '')), 'max': None,
                      'currency': 'unknown', 'unit': 'unknown', 'kind': 'rate_unknown_unit',
                      'VAT': 'unknown', 'quote': redact(text[max(0, m.start() - 40):m.end() + 60])})
    return found


def safe_links(text):
    links = []
    for value in re.findall(r'https?://[^\s<>"\)]+', text):
        try:
            parsed = urllib.parse.urlsplit(value)
            if parsed.username or parsed.password or not parsed.hostname:
                continue
            links.append(urllib.parse.urlunsplit((parsed.scheme, parsed.hostname, parsed.path, '', '')))
        except ValueError:
            continue
    return list(dict.fromkeys(links))[:15]


def valid_inn10(value):
    weights = (2, 4, 10, 3, 5, 9, 4, 6, 8)
    return len(value) == 10 and sum(int(x) * w for x, w in zip(value, weights)) % 11 % 10 == int(value[-1])


def analyze(unit, source, aliases=None):
    text = unit.text
    outstaff, negative = bool(OUTSTAFF.search(text)), bool(NEGATIVE.search(text))
    strong_outstaff = bool(re.search(r'аутста[ф]+|\boutstaff(?:ing)?\b|staff\s+augmentation|\bisOutstaff\s*[:=]\s*true\b', text, re.I))
    stacks = [k for k, pattern in STACKS.items() if re.search(pattern, text, 0 if k == 'Go' else re.I)]
    demand = bool(DEMAND.search(text))
    contract = bool(re.search(r'договор|контракт|contract|соглашени', text, re.I))
    clients = bool(re.search(r'наши\s+клиенты|список\s+клиентов|среди\s+клиентов|our clients|customers|кейс|case study', text, re.I))
    customers, suppliers = role_names(text, 'customer'), role_names(text, 'supplier')
    rates = money_values(text)
    if not (outstaff or (stacks and (demand or contract or clients))):
        return None
    entities = list(dict.fromkeys(LEGAL.findall(text) + customers + suppliers))
    workplaces = re.findall(r'\bcompany\s*:\s*\{[^{}]*?\bname\s*:\s*[\'"]([^\'"\n]+)[\'"]', text, re.S)
    if not workplaces:
        workplaces = re.findall(r'(?im)^\s*company\s*:\s*([^\n|;{}]{2,100})', text)
    entities += [name for name in workplaces if name not in entities]
    alias_entities = []
    for canonical, alternatives in (aliases or {}).items():
        if any(re.search(r'(?<!\w)' + re.escape(a) + r'(?!\w)', text, re.I) for a in [canonical] + alternatives):
            alias_entities.append(canonical)
    entities = list(dict.fromkeys(entities + alias_entities))
    explicit_pair = bool(len(customers) == 1 and len(suppliers) == 1 and strong_outstaff and not negative)
    category = 'outstaff_mention'
    grade = 'D'
    if demand:
        category, grade = ('outstaff_request', 'C') if outstaff else ('hiring_signal', 'C')
    if clients:
        category, grade = 'client_list_or_case', 'C'
    if workplaces and re.search(r'\bisOutstaff\s*[:=]\s*true\b', text, re.I):
        category, grade = 'employee_outstaff_claim', 'B'
    if contract and outstaff:
        category, grade = 'contract_candidate', 'B' if explicit_pair else 'C'
    elif explicit_pair:
        category, grade = 'explicit_relationship_claim', 'B'
    if negative:
        category, grade = 'negative_outstaff_statement', 'D'
    noise = bool(NOISE.search(text) or re.search(r'(^|/)(?:fixtures|testdata|tests|examples|demo)(/|$)', source.get('path', ''), re.I))
    if noise:
        grade = 'X'
        category = 'example_or_synthetic'
    score = 15 + 20 * outstaff + 10 * bool(stacks) + 15 * demand + 10 * contract + 20 * explicit_pair
    score += 10 * bool(customers) + 5 * bool(rates)
    score += 15 * (category == 'employee_outstaff_claim')
    if negative:
        score = min(score, 15)
    if noise:
        score = 0
    evidence = OUTSTAFF.search(text) or DEMAND.search(text)
    start = max(0, (evidence.start() if evidence else 0) - 200)
    quote = redact(text[start:start + 1500])
    years = sorted(set(re.findall(r'(?<!\d)(?:19[89]\d|20[0-3]\d)(?!\d)', text)))
    risks = ['Автоматическая находка: требуется проверка источника человеком.']
    if outstaff and not strong_outstaff:
        risks.append('Термин описывает выделенную команду/предоставление специалистов; модель аутстаффинга не названа прямо.')
    if explicit_pair:
        risks.append('Роли названы в одном фрагменте; подписание, подлинность и текущий статус договора не проверены.')
    if not customers:
        risks.append('Конечный заказчик не установлен.')
    if workplaces:
        risks.append('Компания указана в опыте сотрудника; юридический заказчик и посредники требуют отдельной проверки.')
    if clients:
        risks.append('Кейс/список клиентов сам по себе не подтверждает аутстаффинг.')
    if demand and not outstaff:
        risks.append('Вакансия может быть штатной; покупка внешних специалистов не подтверждена.')
    if years and int(max(years)) < date.today().year:
        risks.append('В тексте только прошлые годы; актуальность спроса неизвестна.')
    if any(x['kind'] == 'salary' for x in rates):
        risks.append('Зарплата выделена отдельно и не считается клиентской ставкой.')
    if re.search(r'(?:анонимн|не раскрыва|NDA|неназванн)', text, re.I):
        risks.append('Есть анонимизация; не угадывать компанию.')
    if len(customers) > 1 or len(suppliers) > 1:
        risks.append('Несколько сторон в одном блоке: пары заказчик–подрядчик автоматически не соединяются.')
    geo = 'RF_cue' if re.search(r'Россия|Российск|\bРФ\b|Москва|Санкт-Петербург|ИНН\s*[:=]?\s*\d{10}(?!\d)', text, re.I) else 'RU_language_only' if re.search(r'[А-Яа-яЁё]', text) else 'unknown'
    action = 'Проверить покупателя, формат привлечения и актуальность; затем найти официальный канал закупок.'
    if category == 'hiring_signal':
        action = 'Проверить готовность компании привлекать внешнюю команду; вакансию использовать только как сигнал спроса.'
    if grade == 'X':
        action = 'Не передавать в продажи как подтверждённый лид.'
    contracts = re.findall(r'(?:договор\w*|контракт\w*)[^\n№]{0,55}№\s*([А-Яа-яA-Za-z0-9][А-Яа-яA-Za-z0-9/_–.-]{1,60})', text, re.I)
    dates = re.findall(r'\b(?:\d{2}\.\d{2}\.20\d{2}|20\d{2}-\d{2}-\d{2})\b', text)
    payment = [redact(m[0].strip()) for m in re.finditer(r'[^\n|;.]{0,30}(?:оплат\w*|отсрочк\w*)[^\n|;.]{0,120}', text, re.I)]
    inns = [value for value in re.findall(r'ИНН\s*[:=]?\s*(\d{10})(?!\d)', text, re.I) if valid_inn10(value)]
    return dict(source, locator=unit.locator, score=min(score, 100), grade=grade, category=category,
                customers=customers, suppliers=suppliers, entities=entities, rates=rates, stacks=stacks,
                years=years, geography=geo, outstaff=outstaff, explicit_pair=explicit_pair,
                review_status='unreviewed', excerpt=quote, limitations=risks, next_action=action,
                contract_numbers=contracts, document_dates=dates, payment_terms=payment,
                legal_entity_inns=inns, workplaces=workplaces, external_links=safe_links(text))
