# zampolit73project

Production PWA-приложение на Laravel + Inertia + Vue.

**Production:** https://zampolit73.duckdns.org

## Что сейчас работает

- Laravel 13 на PHP 8.3;
- Inertia.js 3 + Vue 3;
- Vite 7 + Tailwind CSS 4;
- SQLite;
- Nginx + PHP-FPM без Docker;
- HTTPS через Let's Encrypt / Certbot;
- installable PWA: manifest, service worker, offline fallback;
- Web Push через VAPID;
- страница `/tests` для проверки push-подписки и тестовой отправки;
- push администраторам после успешного production deploy;
- раздел `/projects` с клиентскими инструментами;
- массовый BMP → Quake 1 MIP конвертер с ZIP-выгрузкой;
- внутренний проект каталога публичных презентаций ИТ-директоров без хранения самих файлов;
- интерактивная страница «Сказки Пушкина» с анимированной книгой внутри авторизованной зоны проектов;
- читательский дневник в виде книжной полки с локальными оценками и заметками внутри авторизованной зоны проектов;
- «Рейтинг Коммерсанта» — общая SQLite-база рейтинга топ-менеджеров с редактируемыми LinkedIn-ссылками, вкладками направлений и ответственными за работу;
- `Vacancy Source` — асинхронный MVP расследований вакансий с database queue, живым прогрессом и историей; Telegram Bot уже поддерживает привязку существующих пользователей и приём текста вакансии, а реальный Telegram Reader/web research подключаются следующими итерациями;
- авторизация по username/password с двумя ролями: `admin`, `user`;
- атомарные release-директории с `current` symlink;
- GitHub Actions: test → build → deploy → health checks → admin push.

## Основные страницы

| URL | Доступ | Назначение |
| --- | --- | --- |
| `/` | публичный | главная, «Привет, Валера!», часы Москва / Ульяновск / Берлин |
| `/stas` | admin | персональная страница «Привет, Стас!» с анимацией дружелюбного взмаха рукой |
| `/projects` | авторизованный | каталог проектов |
| `/projects/bmp-to-mip` | авторизованный | массовая конвертация BMP → Quake 1 MIP, выполняется локально в браузере |
| `/projects/cio-presentations` | авторизованный | полный доступ к каталогу презентаций, источникам, сканированию и действиям проекта |
| `/projects/pushkin-fairytales` | авторизованный | интерактивная анимированная книга со сказками Пушкина |
| `/projects/reading-diary` | авторизованный | личная книжная полка с оценками, датами и заметками; данные хранятся в браузере |
| `/projects/kommersant-ranking` | авторизованный | общая рабочая база рейтинга топ-менеджеров «Коммерсанта»: вкладки, LinkedIn, ответственные и кандидаты на проверку |
| `/projects/vacancy-source` | авторизованный | запуск асинхронного расследования вакансии, live-status и история; пока технический каркас без реального Telegram/web поиска |
| `/login` | гость | вход |
| `/admin/users` | admin | список аккаунтов, создание пользователей и управление привязкой Telegram через одноразовые коды |
| `/design-system` | admin | каталог UI-компонентов и дизайн-системы |
| `/tests` | admin | служебные проверки, сейчас Web Push |
| `/up` | публичный | Laravel health endpoint |

Push API находится под auth middleware: `/push/config`, `/push/subscriptions`, `/push/test`.

Telegram Bot webhook: `POST /api/telegram/bot/webhook`. Он stateless и принимает только запросы с корректным `X-Telegram-Bot-Api-Secret-Token`.

## Быстрый локальный запуск

Требования: PHP 8.3+, Composer, Node.js 22+, npm, SQLite extensions для PHP.

```bash
composer install
npm install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan queue:work database --queue=vacancy-source --sleep=1 --tries=1 --timeout=330
npm run dev
```

Тесты:

```bash
vendor/bin/phpunit
```

Production frontend build:

```bash
npm run build
```

## Production

Production работает напрямую на Ubuntu:

```text
Internet
   |
   | 80 / 443
   v
Nginx
   |
   v
PHP 8.3-FPM
   |
   v
Laravel 13
   |
   +-- SQLite
   +-- Inertia / Vue
   +-- Web Push (VAPID)
   +-- Database queue worker (Vacancy Source)
```

Persistent state:

```text
/var/www/zampolit73project/
├── current -> releases/<git-sha>
├── releases/
└── shared/
    ├── .env
    ├── database/database.sqlite
    └── storage/
```

Сертификаты Let's Encrypt находятся в `/etc/letsencrypt/`. Автопродление выполняет `certbot.timer`.

## Deploy

Push в `main` запускает `.github/workflows/deploy.yml`. Backend CI и Frontend Build идут параллельно; после них production проходит отдельные стадии Package → Deploy → Health Check → Notify.

Если в `main` быстро приходят несколько коммитов, предыдущие running/queued production runs автоматически отменяются: до production доходит только самый свежий push.

Последовательность:

```text
Backend CI ─────┐
                ├──> Package ──> Deploy ──> Health Check ──> Notify
Frontend Build ─┘
```

Backend CI запускает Composer и PHPUnit. Frontend Build независимо запускает npm/Vite. Package объединяет проверенный backend/source с собранным `public/build`, после чего Deploy активирует release на VPS. Отдельный Health Check проверяет production снаружи, и только затем Notify отправляет push администраторам.

Обычные повторные deploy ускорены: dependency download caches сохраняются в Actions, VPS не повторяет apt provisioning, при неизменном Composer lock переиспользуется `vendor/`, а Certbot не запускает issuance при уже существующем сертификате.

Deploy считается успешным только после зелёного GitHub Actions run и public HTTPS health check. Public checks ограничены короткими DNS/connect/request timeout'ами и выводят диагностику вместо зависания на десятки минут.

## Secrets и production state

GitHub repository secrets:

- `VPS_HOST`;
- `VPS_USER`;
- `VPS_PWD`.

В Git нельзя коммитить:

- production `.env`;
- реальные пароли;
- приватные SSH-ключи;
- VAPID private key;
- DuckDNS token;
- production SQLite.

DuckDNS token приложению сейчас не нужен. Он понадобится только для автоматического обновления DNS при смене внешнего IP VPS.

## Пользователи

Production-пользователи живут только в SQLite на VPS.

Модель доступа: guest видит только главную `/` и `/login`; `user` получает `/projects` и полный функционал всех проектов; `admin` имеет тот же доступ к проектам плюс административные страницы сайта и управление пользователями.

Администратор создаёт пользовательские аккаунты через `/admin/users`, задавая логин и начальный пароль. Созданный аккаунт всегда получает роль `user`; пароль хранится только в виде Laravel hash.

Seeder содержит dev/bootstrap пользователей `admin` и `user`, но production deploy **не запускает seeders**. Production credentials не должны храниться в Git.

## PWA и Web Push

PWA работает через HTTPS и включает:

- `/site.webmanifest`;
- `/sw.js`;
- offline fallback;
- standalone display;
- install prompt;
- pull-to-refresh;
- push notifications.

VAPID-ключи создаются один раз на сервере скриптом `scripts/ensure-vapid.php` и сохраняются в shared production `.env`.

Чтобы конкретное устройство получало push, администратор должен один раз открыть `/tests`, разрешить уведомления браузеру и создать push-подписку.

После каждого успешного deploy команда `php artisan push:deploy-success` отправляет push всем сохранённым подпискам пользователей с ролью `admin`.

## Структура репозитория

```text
app/
  Console/Commands/       Artisan commands
  Http/Controllers/       auth + push API
  Http/Middleware/        Inertia / access rules
  Models/                 User, PushSubscription
  Services/               WebPushService

config/                    Laravel app/auth/db/session/webpush
database/
  migrations/
  seeders/

public/
  site.webmanifest
  sw.js
  offline.html
  icon-192.svg
  icon-512.svg

resources/
  css/                    app styles + design system
  js/
    components/ui/
    composables/
    layouts/
    pages/
    navigation.js
    pwa.js
    push.js
  views/app.blade.php

routes/
  web.php
  console.php

scripts/
  ensure-vapid.php

tests/
  Feature/
  Unit/

.github/workflows/
  deploy.yml
```

## Документация

- `docs/ARCHITECTURE.md` — устройство приложения и request/data flow.
- `docs/PRODUCTION.md` — VPS, deploy, HTTPS, persistent state и эксплуатация.
- `docs/PWA_PUSH.md` — PWA, service worker, push и VAPID.
- `docs/TECHNICAL_DEBT.md` — известные ограничения и что стоит улучшить дальше.
- `docs/PROJECT_CONTEXT.md` — канонический полный handoff: решения, история, доступы, проекты, CIO scanner и deploy-правила.
- `docs/BMP_TO_MIP.md` — формат Quake MIP и поведение конвертера.
- `docs/CIO_PRESENTATIONS.md` — устройство каталога презентаций, лёгкого сканера и ограничения по трафику/безопасности.
- `docs/KOMMERSANT_RANKING.md` — импорт рейтинга «Коммерсанта», data model, LinkedIn-редактирование и assignment workflow.
- `docs/VACANCY_SOURCE.md` — текущее состояние Vacancy Source, Telegram Bot binding, очередь и границы следующих итераций.
- `AGENTS.md` — обязательные правила разработки для работы с репозиторием.

## Telegram Bot for Vacancy Source

Код интеграции бота не содержит production token. На сервере вручную задаются:

```text
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_USERNAME=...
TELEGRAM_BOT_WEBHOOK_SECRET=...
```

После этого webhook настраивается из текущего release:

```bash
php8.3 artisan telegram:bot:set-webhook
```

Для очистки уже ожидающих Telegram updates при первом подключении можно один раз использовать `--drop-pending`.

Администратор создаёт одноразовый код рядом с пользователем на `/admin/users`; plaintext кода показывается только в текущем ответе админки и не сохраняется в БД. Пользователь отправляет боту `/start CODE`. После привязки обычный текст или Forward создаёт то же `vacancy_investigations`, что и веб-форма; Forward metadata не используется как доказательство.
