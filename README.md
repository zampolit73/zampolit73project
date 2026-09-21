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
- авторизация по username/password с ролями `admin`, `moderator`, `user`;
- атомарные release-директории с `current` symlink;
- GitHub Actions: test → build → deploy → health checks → admin push.

## Основные страницы

| URL | Доступ | Назначение |
| --- | --- | --- |
| `/` | публичный | главная, «Привет, Валера!», часы Москва / Ульяновск / Берлин |
| `/stas` | публичный | персональная страница «Привет, Стас!» с анимацией дружелюбного взмаха рукой |
| `/projects` | публичный | каталог браузерных инструментов |
| `/projects/bmp-to-mip` | публичный | массовая конвертация BMP → Quake 1 MIP, выполняется локально в браузере |
| `/login` | гость | вход |
| `/design-system` | admin, moderator | каталог UI-компонентов и дизайн-системы |
| `/tests` | авторизованный | служебные проверки, сейчас Web Push |
| `/up` | публичный | Laravel health endpoint |

Push API находится под auth middleware: `/push/config`, `/push/subscriptions`, `/push/test`.

## Быстрый локальный запуск

Требования: PHP 8.3+, Composer, Node.js 22+, npm, SQLite extensions для PHP.

```bash
composer install
npm install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
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

Seeder содержит dev/bootstrap пользователей `admin`, `moderator`, `user`, но production deploy **не запускает seeders**. Production credentials не должны храниться в Git.

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

Чтобы конкретное устройство получало push, авторизованный пользователь должен один раз открыть `/tests`, разрешить уведомления браузеру и создать push-подписку.

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
- `docs/PROJECT_CONTEXT.md` — компактный handoff-контекст для продолжения работы в новой сессии.
- `docs/BMP_TO_MIP.md` — формат Quake MIP и поведение конвертера.
- `AGENTS.md` — обязательные правила разработки для работы с репозиторием.
