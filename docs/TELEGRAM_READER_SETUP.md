# Telegram Reader setup plan

Обновлено: 2026-09-25.

Этот документ фиксирует следующий этап Vacancy Source: подключение **рабочих Telegram-чатов пользователя как источника исследования вакансий**.

Важно не путать два разных Telegram-контура:

1. **Telegram Bot** уже работает и принимает от пользователя вакансию.
2. **Telegram Reader** получает отдельный Python/Telethon runtime. До one-time MTProto авторизации и выбора папки он ещё не является активным research source.

Bot API сам по себе не даёт боту доступ к истории личных/рабочих чатов пользователя.

## Текущая точка старта

В production уже есть:

- Vacancy Source web UI;
- Telegram Bot long polling;
- привязка Telegram Bot user ↔ site user;
- database queue;
- web-search v1;
- deterministic scoring;
- кандидаты/источники/история.

Текущая Reader-итерация добавляет:

- Python + Telethon daemon;
- отдельный systemd service/user;
- Unix socket между Laravel и Reader;
- admin-only UI `/admin/telegram-reader`;
- one-time auth: phone → code → optional 2FA;
- Telegram folder selection;
- 3-month backfill;
- sync every ~5 minutes;
- local SQLite corpus + FTS5;
- text/caption only, no media;
- service/status diagnostics.

До выбора папки и завершения backfill система всё ещё считается **web-only + Bot input**. Telegram corpus начинает считаться подключённым только после успешной MTProto авторизации и sync.
## Архитектурное решение

Reader — отдельный лёгкий Python-процесс на том же VPS.

Базовая схема:

```text
Telegram user account
        |
MTProto
        |
Python Telegram Reader
        |
selected Telegram folder
        |
SQLite Telegram corpus
        |
Laravel Vacancy Source
        |
Telegram evidence + web evidence
```

Для MVP использовать один общий Telegram user account.

Предпочтительный Python client: **Telethon**.

Не поднимать отдельный FastAPI/HTTP API без необходимости.

## Постоянные ограничения безопасности

- MTProto session file не хранить в Git.
- MTProto session file не хранить в webroot.
- Session file: mode 0600.
- Reader запускать под отдельным system user/service.
- Laravel/PHP не должен читать MTProto session file напрямую.
- Session file не включать в backup.
- Не скачивать Telegram media.
- Индексировать только text/caption и минимальные message metadata.
- Телефон, API hash, login code, 2FA password и session никогда не коммитить.
- Не просить пользователя присылать API hash, login code или 2FA password в ChatGPT.

## Пошаговая настройка

### Шаг 1 — получить Telegram API credentials

Пользователь самостоятельно открывает:

`https://my.telegram.org`

Далее:

1. войти по своему рабочему/личному Telegram номеру;
2. открыть **API development tools**;
3. создать приложение;
4. получить:
   - `api_id`;
   - `api_hash`.

`api_hash` считается секретом.

Пользователь **не присылает api_hash в чат**.

### Шаг 2 — добавить credentials в GitHub Actions Secrets

Выполнено: пользователь добавил repository secrets:

```text
TELEGRAM_READER_API_ID
TELEGRAM_READER_API_HASH
```

Значения не передавались в ChatGPT и не коммитятся.
### Шаг 3 — реализовать Reader

Реализовано в текущей итерации:

- `telegram_reader/reader.py` + Telethon;
- production env wiring из GitHub Secrets;
- отдельный systemd unit `zampolit73project-telegram-reader.service`;
- отдельный Linux user `zampolit-reader`;
- persistent state under `/var/lib/zampolit73-telegram-reader`;
- session/corpus never live in webroot or Git;
- local Unix socket `/run/zampolit73-telegram-reader/reader.sock`;
- Laravel communicates only through that socket and does not read the session file;
- SQLite corpus with FTS5 when available;
- safe `telegram-reader:diagnose`;
- CI Python syntax check;
- admin-only setup page.

No Docker and no FastAPI.
### Шаг 4 — one-time MTProto authorization

Пользователь не должен снова мучаться с noVNC/terminal.

Нужно сделать **admin-only authorization flow** для Reader, чтобы одноразовый login выполнялся через сайт или другой контролируемый workflow.

UX должен поддерживать:

1. ввод Telegram phone;
2. запрос login code;
3. ввод login code;
4. при включённом Telegram 2FA — ввод password;
5. success state;
6. session сохраняет только Python Reader в закрытой persistent directory.

Laravel может инициировать authorization flow и показывать status, но не должен получать прямой доступ к MTProto session file.

После успешной авторизации повторный login при обычных deploy не требуется.

### Шаг 5 — выбрать рабочую Telegram folder

После авторизации Reader должен получить список Telegram folders/dialog filters.

Admin UI должен позволить выбрать одну рабочую folder, которая и является динамическим whitelist.

Не хардкодить список ~30 chat IDs в конфиг.

Правило:

- чат добавили в выбранную folder → Reader делает backfill;
- чат убрали из folder → Reader прекращает новые sync;
- уже сохранённая история остаётся.

### Шаг 6 — backfill и sync

Для вновь добавленного чата:

- backfill последних **3 месяцев**;
- text + caption;
- без media download;
- сохранять chat ID/title, message ID, date, edit date, deletion state если доступно, message text и source link/reference;
- chat title — display metadata, не scoring signal.

После initial backfill:

- sync примерно каждые **5 минут**;
- edits обновляют текст;
- удалённые сообщения не вычищать из истории без отдельного решения; по возможности маркировать deleted;
- не склеивать автоматически соседние сообщения в одну вакансию.

### Шаг 7 — vacancy-like filter

На ingestion использовать мягкий high-recall filter.

Цель — не тащить весь бытовой чат в индекс, но и не потерять вакансии.

Сохранять:

- likely vacancy message;
- максимум 1–2 слабых соседних text messages как context, если это действительно нужно;
- media не сохранять.

### Шаг 8 — поиск по Telegram corpus

MVP retrieval:

- SQLite FTS5;
- нормализованный текст;
- technology aliases;
- exact/rare phrase retrieval;
- strict similarity/repost clustering;
- дедуп не должен считать десять репостов независимыми доказательствами.

Поиск Telegram и web идёт параллельно.

### Шаг 9 — объединённый scoring

Telegram и web evidence объединяются в существующую deterministic model.

Правила уже зафиксированы:

- geography = 0 scoring weight;
- seniority ≈ 0;
- common stack = weak;
- rare wording/internal name/rare requirement = strong;
- key stack contradiction = strong negative;
- intermediary не занимает top-3 end-client slot;
- Telegram-only strong candidate допустим, но надо явно написать, что подтверждение только Telegram;
- confidence — heuristic, не calibrated probability;
- default visible threshold ≈ 60%.

### Шаг 10 — acceptance check

Настройка Reader считается завершённой только если production проверяет:

1. systemd Reader active;
2. MTProto session authorized;
3. selected folder обнаружена;
4. chat count > 0;
5. initial 3-month backfill completed;
6. новые сообщения sync;
7. Vacancy Source investigation видит Telegram hits;
8. Telegram + web candidates/scoring отражаются в одном результате;
9. Actions полностью зелёные;
10. production HTTPS health-check зелёный.

## Текущий production blocker

Reader foundation задеплоен, systemd service и local Unix socket работают, но прямой MTProto путь с VPS к Telegram сейчас заблокирован/blackholed upstream.

Проверено на production:

```text
telegram_reader=ok
connected=no
authorized=no
auth_state=connection_error
last_error=MTProto connection failed: TimeoutError
```

TCP:443 timeout получен для нескольких стандартных Telegram MTProto DC addresses:

- `149.154.167.51`;
- `149.154.167.91`;
- `149.154.175.50`;
- `91.108.56.100`.

Это не Laravel/UI/session bug: локальный Reader RPC уже отвечает. Следующая задача — дать Telethon рабочий transport через текущую сеть VPS. Предпочтение: локальный/self-hosted bridge к Telegram-owned WSS transport либо исправление маршрута у VPS provider. Внешний Vercel relay не нужен.

## Что должен сделать пользователь прямо сейчас

Пока ничего вводить в `/admin/telegram-reader` не нужно. `api_id` / `api_hash` уже находятся в GitHub Secrets.

Сначала технически добиваем MTProto transport до состояния `connected=yes`. После этого пользователь:

1. открывает `/admin/telegram-reader`;
2. вводит номер Telegram в международном формате;
3. вводит код, который пришлёт Telegram;
4. при 2FA вводит пароль прямо на admin page;
5. выбирает рабочую Telegram folder;
6. ждёт 90-day backfill и проверяет counters.

Телефон, login code и 2FA password не присылать в ChatGPT.
