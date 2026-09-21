# zampolit73project

Проект на Laravel 13 + Inertia 3 + Vue 3, перенесённый из общего `template`.

## Стек
- PHP 8.3+
- Laravel 13
- Inertia.js 3
- Vue 3
- Vite 7
- Tailwind CSS 4
- SQLite
- Nginx + PHP-FPM в production
- HTTPS: Let's Encrypt / Certbot

Docker в production не используется.

## Локальный запуск
```bash
composer install
npm install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
npm run dev
```

Production build:
```bash
npm run build
```

Tests:
```bash
vendor/bin/phpunit
```

## Production

Production URL: `https://zampolit73.duckdns.org`

Push в `main` запускает GitHub Actions:

1. установка PHP/Node зависимостей;
2. PHPUnit;
3. Vite build;
4. упаковка release;
5. SSH deploy на VPS;
6. миграции SQLite;
7. переключение атомарного `current` symlink;
8. запуск Nginx + PHP-FPM;
9. получение/продление сертификата Let's Encrypt через Certbot;
10. редирект HTTP → HTTPS;
11. публичная проверка `/up`, manifest и service worker.

Production state:
```text
/var/www/zampolit73project/
├── current -> releases/<commit>
├── releases/
└── shared/
    ├── .env
    ├── database/database.sqlite
    └── storage/
```

TLS certificates находятся в `/etc/letsencrypt/`. Автопродление выполняет `certbot.timer`.

## GitHub secrets
Используются существующие repository secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PWD`

Не коммить production `.env`, пароли, SSH-ключи и другие секреты.

## PWA / Push
Manifest и service worker доступны через HTTPS, поэтому production готов к обычным PWA-функциям. Web Push пока не является частью deployment и будет подключён отдельно.
