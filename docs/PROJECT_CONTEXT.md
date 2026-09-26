# Полный контекст проекта / handoff

Обновлено: 2026-09-25

Этот файл — канонический handoff по репозиторию `zampolit73/zampolit73project`.
Он нужен для продолжения работы в новой сессии без потери решений, истории и ограничений.

Перед любыми изменениями всё равно обязательно:

1. прочитать `AGENTS.md`;
2. получить актуальный `main` HEAD;
3. перечитать файлы, которых касается задача;
4. перед финальным обновлением `main` повторно проверить HEAD;
5. обновлять `main` только fast-forward, без force push;
6. считать production deploy успешным только после полностью зелёного GitHub Actions run, включая Health Check и Notify.

---

# 1. Репозиторий и production

- Репозиторий: `zampolit73/zampolit73project`
- Основная ветка: `main`
- Production: `https://zampolit73.duckdns.org`
- Актуальные `main` HEAD, GitHub Actions, PR и deployment status намеренно не фиксируются здесь как источник истины: перед работой их нужно получать непосредственно из GitHub.
- Production работает напрямую на Ubuntu, без Docker/Compose.

Текущий production stack:

- Nginx;
- PHP 8.3-FPM;
- Laravel 13;
- Inertia Laravel 3;
- Vue 3.5;
- Vite 7;
- Tailwind CSS 4;
- SQLite;
- Let's Encrypt / Certbot;
- PWA;
- Web Push / VAPID;
- GitHub Actions CI/CD;
- Laravel database queue worker for Vacancy Source, managed by systemd;
- Laravel Telegram Bot long poller for Vacancy Source, managed by systemd.

Production layout:

```text
/var/www/zampolit73project/
├── current -> releases/<sha>
├── releases/
└── shared/
    ├── .env
    ├── database/database.sqlite
    └── storage/
```

Persistent state всегда должен оставаться вне release-директорий.

Нельзя коммитить:

- production `.env`;
- production SQLite;
- реальные пароли;
- приватные SSH-ключи;
- VAPID private key;
- DuckDNS token;
- другие секреты.

---

# 2. Общая форма продукта

Сайт — это одно Laravel/Inertia/Vue приложение с несколькими самостоятельными проектами внутри раздела **«Проекты»**.

Пользовательское правило: новые инструменты должны жить внутри существующего раздела `/projects`, а не становиться случайными независимыми top-level страницами.

Текущие проекты:

1. `/projects/bmp-to-mip`
2. `/projects/cio-presentations`
3. `/projects/pushkin-fairytales`
4. `/projects/reading-diary`
5. `/projects/kommersant-ranking`
6. `/projects/vacancy-source`

Раздел `/projects` — общий селектор проектов.

Проект с площадкой для дрессировки собак был создан экспериментально, затем **полностью удалён** commit `7a038f2c266e99bf8944a52db0e8bea097c3d281`.
Не восстанавливать его и не возвращать маршрут/карточку/страницу без отдельного запроса.

---

# 3. Визуальный язык

Сайт должен сохранять уже сложившийся editorial/poster стиль:

- тёплый кремовый фон;
- почти чёрный основной цвет;
- глубокий красный акцент;
- толстые рамки;
- offset shadows;
- condensed display typography;
- геометрические декоративные элементы;
- минимум generic SaaS-карточек и округлых dashboard-компонентов.

Responsive обязателен:

- usable с 320 px;
- без случайного горизонтального скролла страницы;
- читаемо без browser zoom;
- touch targets пригодны для мобильного;
- sticky/fixed UI не должен закрывать контент;
- учитывать не только широкие мониторы, но и небольшую высоту ноутбука.

---

# 4. Архитектурные правила

Laravel отвечает за:

- маршруты;
- session auth;
- роли;
- серверные mutation endpoints;
- persistence;
- migrations.

Inertia связывает Laravel и Vue.

Frontend:

- страницы: `resources/js/pages/`;
- shared components: `resources/js/components/`;
- layouts: `resources/js/layouts/`;
- navigation: `resources/js/navigation.js`;
- frontend entrypoint: `resources/js/app.js`;
- общие стили: `resources/css/`.

Blade используется только как минимальная Inertia shell.

Новый user-facing section обычно должен включать в одной задаче:

- route;
- Vue page;
- navigation entry;
- tests;
- docs.

---

# 5. CI/CD и production deploy

Workflow: `.github/workflows/deploy.yml`.

Pipeline:

```text
Backend CI ─────┐
                ├──> Package ──> Deploy ──> Health Check ──> Notify
Frontend Build ─┘
```

Backend CI:

- PHP 8.3;
- Composer;
- PHPUnit.

Frontend Build:

- Node.js 22;
- npm;
- Vite build;
- JS smoke tests where applicable.

Deploy:

- собирает release;
- подцепляет shared `.env`, SQLite и storage;
- запускает migrations с `--force`;
- не запускает seeders;
- оптимизирует Laravel;
- атомарно переключает `current` symlink;
- reload Nginx;
- выполняет локальные проверки.

Health Check:

- проверяет DNS;
- HTTPS `/up`;
- homepage;
- PWA manifest;
- service worker;
- HTTP → HTTPS redirect.

В health-check уже внесены три стабилизации:

1. локальные post-reload HTTPS checks используют retry, чтобы переживать короткий socket handoff после `nginx reload`;
2. публичный health-check один раз получает IPv4 через `getent ahostsv4`, затем использует curl `--resolve`, чтобы повторные DNS lookup на hosted runner не роняли deploy;
3. публичные HTTPS probes имеют увеличенный budget на connect/TLS handshake (до 8 секунд на попытку) и bounded retries, потому что hosted runners могут быстро установить TCP, но эпизодически задержать TLS handshake дольше прежних 3 секунд.

После успешного Health Check job `Notify` отправляет deploy-success Web Push.

Production deploy нельзя считать успешным до полного зелёного run.

---

# 6. Навигация после atomic deploy

В прошлом долгоживущие вкладки могли ссылаться на старые hashed Vite chunks после atomic deploy.

Для защиты от этого:

- карточки проектов в `Projects.vue` используют обычные full-document `<a>`, а не Inertia `Link`;
- `resources/js/app.js` слушает `vite:preloadError` и перезагружает страницу.

Это общее hardening-поведение. Не удалять его без причины.

---

# 7. Модель доступа и роли

Это важное текущее решение.

В базе только две роли:

- `admin`;
- `user`.

`guest` — не роль в БД, а неавторизованная сессия.

Модель доступа:

## Guest

Не вошедший пользователь видит только пользовательские страницы:

- `/`
- `/login`

Попытка открыть `/projects` или любой `/projects/*` должна отправлять на `/login`.

## User

Авторизованный `user` получает:

- главную;
- `/projects`;
- **полный функционал всех проектов**.

Внутри проектов у `user` те же действия, что и у `admin`.

Особенно важно: в CIO PRESENTATIONS `user` может:

- смотреть презентации;
- фильтровать;
- добавлять источники;
- запускать сканирование;
- добавлять презентации вручную;
- менять статусы и флаги;
- очищать презентации;
- пользоваться вкладками «Источники» и «Поиск / сканирование».

## Admin

`admin` имеет тот же полный доступ к проектам плюс site-administration функции:

- `/admin/users`;
- `/admin/telegram-reader`;
- `/stas`;
- `/design-system`;
- `/tests`.

Текущее различие между `admin` и `user` относится к администрированию сайта, а не к возможностям проектов.

Legacy роли, отличные от `admin` / `user`, миграцией нормализуются в `user`.

---

# 8. Администрирование пользователей

Admin page:

`/admin/users`

Реализация:

- `app/Http/Controllers/Admin/UserController.php`
- `resources/js/pages/AdminUsers.vue`
- admin-only middleware: `EnsureUserIsAdmin`

Возможности сейчас:

- увидеть список аккаунтов;
- создать нового пользователя;
- задать username;
- задать начальный пароль;
- повторить пароль для подтверждения;
- видеть Telegram-привязку пользователя;
- создать одноразовый Telegram-код без автоматического срока действия;
- отвязать текущий Telegram-аккаунт пользователя.

Правила:

- аккаунт, созданный через админку, всегда имеет роль `user`;
- нельзя через эту форму создать ещё одного admin;
- пароль минимум 8 символов;
- username уникален;
- пароль сразу хешируется через Laravel `Hash`;
- plaintext пароль не хранится;
- plaintext пароль не возвращается в список пользователей;
- после создания администратор должен передать начальный пароль пользователю самостоятельно безопасным способом.

Telegram-коды хранятся только как SHA-256 hash; plaintext показывается админу только в ответе после создания. Новый неиспользованный код инвалидирует предыдущий неиспользованный код этого пользователя. Один site user может быть привязан только к одному Telegram user, и один Telegram user — только к одному site user.

Отдельная admin page `/admin/telegram-reader` управляет MTProto Reader: one-time phone/code/optional-2FA login, выбор рабочей Telegram folder и ручной sync. Она не раскрывает и не читает MTProto session file напрямую.

На данный момент в админке **нет** функций удаления пользователя, блокировки или сброса пароля.
Не добавлять их как будто они уже существуют.

Production users живут в SQLite на VPS.
Seeder — только dev/bootstrap helper и production deploy его не запускает.

---

# 9. Текущая route-модель

Public:

- `GET /`
- `GET /login`
- `POST /login`
- `GET /up`

Authenticated:

- `GET /projects`
- `GET /projects/bmp-to-mip`
- `GET /projects/cio-presentations`
- `GET /projects/pushkin-fairytales`
- `GET /projects/reading-diary`
- `GET /projects/kommersant-ranking`
- `GET /projects/vacancy-source`
- Vacancy Source investigation create/status/cancel routes
- Kommersant ranking manager/candidate mutation routes
- CIO mutation routes
- push API
- `POST /logout`

Admin-only:

- `GET /admin/users`
- `POST /admin/users`
- `POST /admin/users/{user}/telegram-invite`
- `DELETE /admin/users/{user}/telegram-binding`
- `GET /admin/telegram-reader`
- `POST /admin/telegram-reader/request-code`
- `POST /admin/telegram-reader/submit-code`
- `POST /admin/telegram-reader/submit-password`
- `POST /admin/telegram-reader/select-folder`
- `POST /admin/telegram-reader/sync`
- `GET /stas`
- `GET /design-system`
- `GET /tests`

Push API остаётся под auth.

Отдельно есть stateless webhook `POST /api/telegram/bot/webhook`. Он не использует session auth и принимает update только при совпадении `X-Telegram-Bot-Api-Secret-Token` с production secret.

---

# 10. Проект №01 — BMP → MIP

Route:

`/projects/bmp-to-mip`

Назначение:

браузерный массовый конвертер BMP → Quake 1 MIP/miptex.

Ключевые решения:

- исходные картинки не загружаются на сервер;
- конвертация идёт в браузере;
- classic Quake 1 palette quantization;
- normal source images не должны случайно превращаться в fullbright;
- размеры приводятся к допустимым multiple-of-16;
- пропорции сохраняются;
- oversized inputs уменьшаются;
- контент центрируется;
- края растягиваются вместо чёрных полос;
- пишется little-endian miptex с четырьмя mip levels;
- успешные результаты собираются в ZIP;
- плохой файл не должен ронять весь batch.

Основные файлы:

- `resources/js/pages/BmpToMip.vue`
- `resources/js/lib/quakeMip.js`
- `tests/js/quake-mip.mjs`
- `docs/BMP_TO_MIP.md`

---

# 11. Проект №02 — CIO PRESENTATIONS

Route:

`/projects/cio-presentations`

Назначение:

внутренний sales-support инструмент для поиска публичных презентаций CIO / ИТ-директоров и ручной квалификации.

Главный вопрос MVP:

> Можно ли регулярно находить полезные публичные презентации ИТ-руководителей на бесплатных источниках, не создавая тяжёлую нагрузку на VPS?

Это **каталог ссылок и метаданных**, а не PDF-хранилище и не CRM для рассылки.

## 11.1. Критические продуктовые решения

Не менять без отдельного запроса:

- не скачивать презентации автоматически на VPS;
- не хранить presentation files на VPS;
- не проксировать скачивание презентаций через VPS;
- «Открыть презентацию» должен вести браузер пользователя прямо на исходный URL;
- сервер может получать только лёгкие HTML/XML страницы для discovery;
- текущий MVP остаётся бесплатным;
- не добавлять paid search API;
- не добавлять paid scraping/proxy service;
- не добавлять LLM для discovery без отдельного решения;
- не делать OCR;
- не парсить содержимое PDF;
- контакты из презентаций автоматически не извлекаются;
- нет автоматической холодной рассылки;
- manual review остаётся частью workflow.

Желаемая схема трафика:

```text
VPS -> source HTML/XML only
User browser -> original PDF/PPT/PPTX directly
```

Нельзя превращать её в:

```text
source -> VPS -> user
```

для самих презентационных файлов.

## 11.2. Доступ

`admin` и `user` имеют одинаковый полный доступ к проекту.

Guest → login.

## 11.3. Основные backend файлы

- `app/Http/Controllers/CioPresentationController.php`
- `app/Services/PublicPresentationScanner.php`
- `app/Models/Presentation.php`
- `app/Models/PresentationSource.php`

Frontend:

- `resources/js/pages/CioPresentations.vue`
- CIO styles в `resources/css/app.css`

Docs:

- `docs/CIO_PRESENTATIONS.md`

## 11.4. Интерфейс

Вкладки:

- Обзор
- Презентации
- Источники
- Поиск / сканирование

Функции:

- статистика;
- фильтры;
- поиск;
- список presentation candidates;
- список источников;
- ручное добавление источника;
- ручное добавление presentation URL;
- ручной запуск сканирования;
- review flags;
- открыть оригинальную презентацию;
- очистить найденные презентации.

Review flags:

- email есть;
- телефон есть;
- хороший лид;
- проверено;
- не подходит;
- другие существующие review/link statuses.

## 11.5. Data model

`presentation_sources`:

- name;
- url;
- domain;
- priority;
- is_active;
- last_scanned_at;
- last_scan_found;
- last_error;
- timestamps.

`presentations`:

- optional source;
- title;
- speaker_name;
- job_title;
- company;
- event_name;
- event_year;
- file_type;
- file_url;
- source_page_url;
- review_status;
- link_status;
- has_email;
- has_phone;
- is_good_lead;
- discovered_at;
- reviewed_at;
- assigned_to_user_id;
- assigned_at.

`file_url` unique и служит основным URL-level dedupe.

## 11.5.1. Ответственный / «Взять в работу»

У презентации может быть один ответственный пользователь.

Workflow:

- свободная презентация показывает «Свободна» и кнопку «Взять в работу»;
- текущий пользователь может атомарно забрать свободную презентацию на себя;
- после назначения на карточке видны username и время назначения;
- свои карточки помечаются «Моя» и визуально выделяются;
- пользователь может «Снять с себя» только собственное назначение;
- чужое назначение нельзя перехватить или снять;
- assignment сохраняется при «Проверено» / «Не подходит», чтобы было видно, кто обработал презентацию;
- фильтр «Ответственный» умеет: Все / Свободные / Мои / Все в работе / конкретный пользователь;
- в заголовке списка показываются счётчики «В работе» и «Моих».

Поля:

- `assigned_to_user_id` — nullable FK на `users`;
- `assigned_at` — время, когда презентацию взяли в работу.

При удалении user FK становится null, сама презентация не удаляется.

## 11.6. Очистка презентаций

Кнопка **«Очистить презентации»**:

- hard-delete всех строк `presentations`;
- источники сохраняются;
- `last_scanned_at` сбрасывается;
- `last_scan_found` → 0;
- `last_error` очищается;
- требуется browser confirmation.

Ранее migration выполнила один раз production reset истории.
Не делать новые migrations, которые при каждом deploy снова очищают презентации.

Дальнейшая очистка — только явным действием пользователя, если отдельно не заказан другой one-time reset.

## 11.7. Approved preset sources

Текущие одобренные preset families:

- TAdviser / TAdviser SummIT;
- CNews / CNews FORUM Кейсы;
- Industrial++;
- ЦИПР;
- IB-Bank / «Цифровая устойчивость промышленных систем».

Нельзя без отдельного решения возвращать:

- **1C** как preset;
- **Global CIO** как preset.

Manual sources пользователя — отдельная категория.
Preset-maintenance migrations не должны удалять manual rows без явного запроса.

Важные добавленные discovery points:

- `https://cnewsforum.ru/cases/presentations`
- `https://industrialconf.ru/2025/abstracts`
- `https://cipr-reports.ru/`
- `https://cipr.ru/media-2025/`
- `https://tsups.ib-bank.ru/materials`

2026 TAdviser/CNews sources также уже добавлены отдельным preset layer.

## 11.8. Scanner

Текущий scanner остаётся намеренно ограниченным.

Для выбранного source:

1. валидирует публичный HTTP/HTTPS URL;
2. получает source HTML/XML;
3. находит direct links на `.pdf`, `.ppt`, `.pptx`;
4. может пройти до **6 релевантных same-origin HTML страниц**;
5. глубина обхода — один уровень;
6. пытается получить root `/sitemap.xml`;
7. не скачивает presentation files;
8. найденные URL сохраняются как candidates.

Приоритет discovery links:

- presentation;
- materials;
- abstracts;
- reports;
- speakers;
- program;
- «презентация»;
- «материалы»;
- «доклад»;
- «спикер»;
- CIO / CTO / CDO;
- ИТ-директор;
- цифровизация;
- industrial / промышленность.

Неинтересные категории вроде registration/sponsors/partners должны иметь отрицательный приоритет.

Security / traffic:

- только HTTP/HTTPS;
- только 80/443;
- localhost запрещён;
- private/reserved IP запрещены;
- redirects revalidate destination;
- короткие connect/request timeouts;
- HTML/XML size cap;
- bounded page count;
- manual scans в MVP.

## 11.9. Ошибки сканирования

Была production проблема: внешний сайт мог оборвать соединение/зависнуть, Laravel HTTP client кидал `ConnectionException`, а код ловил только `RuntimeException`. В результате Inertia показывал белый `500 Server Error`.

Исправлено:

- `ConnectionException` нормализуется в понятную scan error;
- ожидаемые scanner errors сохраняются в `presentation_sources.last_error`;
- UI получает validation error вместо 500;
- неожиданные `Throwable` логируются через Laravel `report()`, а пользователю отдаётся безопасная ошибка;
- malformed origin явно отклоняется.

Не возвращать поведение, где recoverable source/network failure роняет страницу 500.

## 11.10. Следующий логичный слой развития CIO

Уже обсуждавшееся направление, но пока не реализованное полностью:

- кнопка «Просканировать все активные»;
- source-specific metadata extraction из surrounding HTML;
- автоматически заполнять ФИО, должность, компанию и название доклада без чтения PDF;
- сохранять строгие per-domain/request caps;
- не расширять crawler до бесконтрольного recursive обхода;
- не скачивать presentation files.

Лучше углублять известные high-value sources, чем строить широкий агрессивный crawler.

---

# 12. Проект №03 — «Сказки Пушкина»

Route:

`/projects/pushkin-fairytales`

Проект доступен после авторизации.

Основной файл:

- `resources/js/pages/PushkinFairytales.vue`

Решения:

- frontend-only;
- без БД;
- без внешних изображений;
- без third-party visual libraries;
- animated 3D book;
- книга открывается и перелистывает страницы;
- ручная навигация;
- autoplay;
- pause/play;
- CSS-only illustrations / ornaments;
- cream/black/red база + dark blue night / muted gold;
- mobile adaptation;
- `prefers-reduced-motion` отключает тяжёлые motion effects и autoplay.

Тематические spreads:

- золотая рыбка;
- царь Салтан;
- мёртвая царевна;
- золотой петушок;
- Балда.

Этот проект используется также как ссылка из предустановленной книги в читательском дневнике.

---

# 13. Проект №04 — «Читательский дневник»

Route:

`/projects/reading-diary`

Проект доступен после авторизации, но **данные дневника не привязаны к аккаунту**.

Storage:

- browser `localStorage`;
- key: `zampolit73.reading-diary.v1`.

То есть:

- сервер не получает записи дневника;
- БД для дневника нет;
- разные браузеры/устройства автоматически не синхронизируются;
- два пользователя в одном и том же browser profile фактически увидят один и тот же localStorage state.

Основной файл:

- `resources/js/pages/ReadingDiary.vue`

UI:

- деревянная книжная полка;
- интерактивные корешки;
- карточка выбранной книги;
- 1–5 stars;
- дата прочтения;
- заметка;
- добавление собственной книги;
- удаление пользовательской книги;
- статистика total/rated/average.

Предустановленная книга:

- **Сказки Пушкина**
- Александр Пушкин
- starter entry
- не удаляется
- изначально без оценки
- изначально без даты
- изначально без личной заметки
- содержит переход в `/projects/pushkin-fairytales`.

Важно: не придумывать пользователю оценку за него.

Docs:

- `docs/READING_DIARY.md`

---

# 14. Проект №05 — «Рейтинг Коммерсанта»

Route:

`/projects/kommersant-ranking`

Назначение:

общая командная рабочая база по рейтингу топ-менеджеров «Коммерсанта» 2026 года с сохранением структуры направлений исходной таблицы, редактируемыми LinkedIn-ссылками и ответственными.

Доступ:

- guest → login;
- `user` и `admin` имеют одинаковый полный доступ к проекту;
- данные общие для команды и хранятся в production SQLite.

Исходный импорт 2026:

- 19 рейтинговых направлений;
- 1120 manager rows по фактическим строкам вкладок;
- 338 исходно заполненных LinkedIn URL;
- 55 записей во вкладке «Кандидаты на проверку»;
- источник: газета «Коммерсантъ», №171 от 17 сентября 2026 года / `KOM_171_170926.pdf`.

Важно: название исходного workbook содержит TOP-1000, но фактические 19 рейтинговых вкладок содержат 1120 строк. Приложение показывает фактически импортированные записи, а не искусственно обрезает их до 1000.

Вкладки:

- Обзор;
- все 19 направлений рейтинга в исходном порядке;
- Кандидаты на проверку.

Основная таблица направления:

`№ | Отрасль | Место | Ф.И.О. | LinkedIn | В работе у | Должность | Компания | Стр. PDF`

Workflow ответственного:

- свободную строку можно атомарно «Взять в работу»;
- у строки максимум один ответственный;
- свою строку можно «Снять с себя»;
- чужую строку нельзя перехватить или освободить;
- assignment хранится через nullable FK на `users` и timestamp;
- тот же workflow применяется к кандидатам на проверку;
- фильтр поддерживает свободные / мои / все в работе / конкретного пользователя.

LinkedIn:

- ссылка редактируется inline прямо в таблице;
- можно добавить, заменить или очистить URL;
- принимаются только HTTP/HTTPS URL домена `linkedin.com` и его subdomains;
- изменения сохраняются в общей БД;
- assignment и LinkedIn actions пишутся в activity history.

Persistence:

- `kommersant_categories`;
- `kommersant_managers`;
- `kommersant_candidates`;
- `kommersant_activities`.

Начальный dataset хранится в Git как gzip-compressed JSON, разбитый на небольшие base64 text chunks под `database/data/kommersant-ranking-2026/`.
Migration импортирует его **один раз** при создании таблиц.

Критическое правило для будущих обновлений рейтинга:

- обычный deploy не должен повторно импортировать dataset;
- будущий импорт нового года не должен молча перезаписывать ручные LinkedIn-исправления, assignment или другую рабочую историю;
- обновление данных должно быть отдельным явным продуктовым решением и migration/import strategy.

Основные файлы:

- `app/Http/Controllers/KommersantRankingController.php`;
- `app/Models/KommersantCategory.php`;
- `app/Models/KommersantManager.php`;
- `app/Models/KommersantCandidate.php`;
- `app/Models/KommersantActivity.php`;
- `resources/js/pages/KommersantRanking.vue`;
- `resources/css/kommersant-ranking.css`;
- `docs/KOMMERSANT_RANKING.md`.

Responsive:

- UI сохраняет общий cream/black/deep-red editorial/poster язык;
- tabs и широкие data tables скроллятся внутри собственных контейнеров;
- страница не должна создавать общий horizontal overflow на 320px.

---

# 15. PWA / Web Push

Активные компоненты:

- `public/site.webmanifest`
- `public/sw.js`
- `resources/js/pwa.js`
- `resources/js/push.js`
- `PushSubscriptionController`
- `WebPushService`
- `scripts/ensure-vapid.php`

VAPID private key только в production `.env`.

Admin-only page `/tests` используется для browser push tests.

После успешного production deploy отправляется deploy-success push администраторам.

---

# 16. Важные исторические commits и зачем они нужны

Хронология последних значимых изменений:

- `860671195ac5d4205e521b8c7e863d6e9999d498` — исправление UTF-8 названий ссылок презентаций;
- `35b0c8671f7357ba84c51c84d9dc37e92b6daa5e` — первичная предустановка CIO sources;
- `f29b5f57c58f65a873c448a29c4118418e583d68` — presets заменены на TAdviser/CNews;
- `039090139b1907532d8c0ba0d5a6a4255f8e40f6` — reset CIO history + 2026 sources;
- `89651a7c340ee37a305dca36691d7608533516d8` — animated Pushkin project;
- `94e69807ad107095c639034ea0096adc2733d205` — hardening project navigation after deploys;
- `7a038f2c266e99bf8944a52db0e8bea097c3d281` — dog project полностью удалён;
- `d85b3e33ad7c4526ceea5ee1b8e1e19f61f02d1d` — bookshelf reading diary;
- `2b6ec1c88f6295495c2a3286ddd8346406aafc41` — новые CIO discovery sources + bounded depth-1 scanning;
- `a9d80d7f7bbe7171a842f4e5a6dc6b71fff01280` — retry локальных health checks после nginx reload;
- `be672daa16ee7533d4771ed9cccdd3925e93c3c6` — scan failures больше не дают пользователю 500;
- `9c7a0250da45010f48575fcd1b250df8bf56ee74` — стабилизация public health-check DNS;
- `9c23a27055274d787cc246dcec5a27177e910e22` — проекты закрыты auth, роли сокращены до admin/user;
- `256f8fe1a901cb02ef0e14ae42388bb0ef53ebcf` — admin user management;
- `5b4769561aa7ecd28e1f1a7fe43232b05f5967da` — `user` получил полный функционал всех проектов;
- `b81698f35a830c0ee1133ee2500d3afe9af0ec92` — assignment workflow «Взять в работу» для CIO presentations.

Этот список — исторические опорные изменения, а не live-список последних commits. Текущий HEAD всегда проверять непосредственно в GitHub.

---

# 17. Что не надо случайно откатывать

При следующих изменениях особенно не сломать:

1. Guest не должен получать доступ к `/projects*`.
2. User должен иметь полный функционал проектов.
3. Admin-only должна оставаться именно site administration, прежде всего `/admin/users`.
4. Не возвращать роль `moderator` без явного решения.
5. Не возвращать dog-training-ground project.
6. Не возвращать 1C preset.
7. Не возвращать Global CIO preset без явного одобрения.
8. Не скачивать PDF/PPT/PPTX на VPS.
9. Не проксировать presentation files.
10. Не удалять manual presentation sources миграцией presets.
11. Не делать migrations, которые повторно очищают presentation history каждый deploy.
12. Не убирать scanner SSRF protections.
13. Не убирать scan error handling, из-за которого внешняя сеть не валит UI 500.
14. Не убирать full-document project navigation / `vite:preloadError` reload protection без проверки причины.
15. Не запускать production seeders.
16. Не коммитить secrets.
17. Не считать deploy успешным до полного зелёного pipeline.
18. Не разрешать молча перехватывать презентацию, уже назначенную другому пользователю.
19. Не очищать assignment автоматически при review status; ответственность должна сохраняться.
20. Не запускать повторный импорт Kommersant dataset на обычном deploy.
21. Не перезаписывать ручные LinkedIn-исправления и assignment в «Рейтинге Коммерсанта» будущей миграцией без отдельного решения.
22. Не разрешать перехватывать manager/candidate, уже назначенного другому пользователю.

---

# 18. Чек-лист для следующей задачи

Перед работой:

- получить свежий `main`;
- прочитать `AGENTS.md`;
- прочитать этот файл;
- прочитать профильный doc;
- прочитать текущие routes/controller/page/tests;
- проверить migrations, если задача касается данных.

Если задача про CIO:

- проверить `docs/CIO_PRESENTATIONS.md`;
- проверить `PublicPresentationScanner.php`;
- не трогать presentation binaries;
- соблюдать bounded traffic;
- помнить approved preset families;
- проверить regular `user`, а не только admin.

Если задача про «Рейтинг Коммерсанта»:

- проверить `docs/KOMMERSANT_RANKING.md`;
- помнить, что данные shared SQLite, а не browser local;
- не перезаписывать ручные LinkedIn/assignment повторным импортом;
- проверять regular `user`, а не только admin;
- сохранять atomic assignment semantics.

Если задача про auth:

- guest = только home/login;
- user = полный доступ к projects;
- admin = projects + site admin;
- создание users только через admin page;
- новый account получает role `user`.

Если задача про deploy:

- не force push;
- один логический commit;
- дождаться Backend CI;
- дождаться Frontend Build;
- дождаться Package;
- дождаться Deploy;
- дождаться Health Check;
- дождаться Notify;
- только после этого сообщать production success.

---

# 19. Куда смотреть дальше

Основные документы:

- `AGENTS.md` — обязательные repo rules;
- `README.md` — короткое состояние приложения;
- `docs/PROJECT_CONTEXT.md` — этот полный handoff;
- `docs/ARCHITECTURE.md` — архитектура;
- `docs/PRODUCTION.md` — VPS/deploy;
- `docs/PWA_PUSH.md` — PWA/Web Push;
- `docs/CIO_PRESENTATIONS.md` — CIO project;
- `docs/BMP_TO_MIP.md` — Quake converter;
- `docs/READING_DIARY.md` — reading diary;
- `docs/KOMMERSANT_RANKING.md` — рейтинг «Коммерсанта», импорт, LinkedIn и assignment workflow;
- `docs/TECHNICAL_DEBT.md` — известный technical debt.

Этот handoff описывает **текущее намерение продукта**, но код и актуальный `main` всегда имеют приоритет при проверке фактического состояния.


## Vacancy Source — current technical state

Project #06: `/projects/vacancy-source`.

Implemented now:

- authenticated web launcher;
- private Telegram Bot input bound to existing site users;
- Bot production transport via long polling and pinned reachable Telegram API IPv4;
- Laravel database queue `vacancy-source` with one production worker;
- live web status polling and history;
- queued-only cancellation;
- normalized vacancy text + fingerprint;
- **real public-web search** through Bing RSS SERP, with no paid search key;
- deterministic signal extraction and scoring;
- up to three end-client candidates above the 60% threshold;
- direct vs indirect hypothesis label;
- intermediaries separated with `is_end_client=false`;
- persisted source links/snippets/evidence scores in `investigation_sources`;
- web UI shows candidates, explanations and clickable sources;
- Telegram progress/final result and `/status` include persisted candidate/source information;
- deploy probe `vacancy:web:probe` checks public search connectivity without user data.

Current web-search v1 deliberately scores Bing result titles/snippets and does not pretend that full page content was verified when it was not fetched. Query generation uses rare requirement phrases, technology combinations, HH/Habr targeted searches, and RU/EN role variants. Scoring weights are explicit in `config/vacancy_source.php`; geography is zero-weight and seniority is effectively zero-weight.

## Vacancy Source — Telegram Reader setup state

Canonical runbook: `docs/TELEGRAM_READER_SETUP.md`.

Implemented in the current Reader iteration:

- Python Telethon daemon under `telegram_reader/`;
- GitHub Secrets `TELEGRAM_READER_API_ID` / `TELEGRAM_READER_API_HASH` are the only MTProto credentials provided to deploy;
- production service `zampolit73project-telegram-reader.service`;
- dedicated Linux identity `zampolit-reader`;
- session + Reader corpus under `/var/lib/zampolit73-telegram-reader`, outside webroot;
- state directory mode 0700; Laravel/PHP cannot read the MTProto session directly;
- local Unix socket `/run/zampolit73-telegram-reader/reader.sock` is the Laravel↔Reader control boundary;
- admin-only page `/admin/telegram-reader`;
- one-time login flow: phone → Telegram code → optional 2FA password;
- Reader can enumerate Telegram folders/dialog filters and select one explicit work folder;
- selected folder is the dynamic whitelist;
- 90-day backfill on selection/newly indexed chats;
- periodic sync around every 5 minutes;
- soft high-recall vacancy-like filter;
- text/caption only, no media download;
- local SQLite corpus with FTS5 when available;
- new/edit events upsert; delete events are marked deleted best-effort while the Reader is running;
- deploy diagnostics via `telegram-reader:diagnose`.

GitHub Reader secrets have already been added by the user. Do not ask for `api_hash`, login code or 2FA password in ChatGPT.

**MTProto transport is now working in production.** Direct Telegram DC TCP is blackholed by the VPS route, so the Reader uses a **local-only MTProto→Telegram WSS bridge** on `127.0.0.1:1443`. The bridge is `Flowseal/tg-ws-proxy` pinned to commit `caa949bee0873d2b95dfb4fbeb1b7868b0ee3843` (MIT), runs as `zampolit-reader`, is never exposed publicly, and is started as `zampolit73project-telegram-ws-bridge.service`. Cloudflare proxy fallback is explicitly disabled (`--no-cfproxy`): the bridge targets Telegram-owned WSS endpoints, so this does not introduce Vercel or another hosted relay. Telethon connects to the local bridge with `ConnectionTcpMTProxyRandomizedIntermediate`.

Verified in production deploy diagnostics:

```text
telegram_reader=ok
connected=yes
authorized=no
auth_state=not_authorized
transport=local_wss_bridge
selected_folder=none
chat_count=0
indexed_message_count=0
fts_enabled=yes
```

The transport blocker is cleared. **Next manual step:** the user opens `/admin/telegram-reader` and completes the one-time MTProto login there: phone → Telegram code → optional 2FA password. Phone/code/2FA are entered only on the admin page and must not be pasted into ChatGPT.

Still not integrated into investigation scoring:

- Reader FTS search results are not yet merged into `VacancyWebResearchService`;
- strict repost/source clustering for Telegram evidence is still pending;
- Telegram + web combined confidence/scoring is still pending;
- admin review UI and quality metrics remain pending.
### Vacancy Source Telegram Bot networking

The VPS route to the DNS-selected `api.telegram.org` address is unreliable, while `149.154.167.220` is reachable. Production therefore uses `TELEGRAM_BOT_API_IP=149.154.167.220` inside `TelegramBotClient`, preserving `api.telegram.org` for TLS/SNI. The Bot runs as `zampolit73project-telegram-bot.service` with `telegram:bot:poll`; existing webhook updates were preserved with `drop_pending_updates=false`.

