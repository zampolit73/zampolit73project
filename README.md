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
Push в `main` запускает GitHub Actions:

1. установка PHP/Node зависимостей;
2. PHPUnit;
3. Vite build;
4. упаковка release;
5. SSH deploy на VPS;
6. миграции SQLite;
7. переключение атомарного `current` symlink;
8. запуск Nginx + PHP-FPM;
9. публичная проверка `/up`;
10. после успешной проверки — удаление Docker с VPS.

До регистрации домена приложение работает по HTTP на IP VPS. HTTPS/Certbot подключим отдельным изменением после появления поддомена.

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

## GitHub secrets
Используются существующие repository secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PWD`

Не коммить production `.env`, пароли, SSH-ключи и другие секреты.

## PWA / Push
PWA-файлы сохранены и исправлены под существующие маршруты. Web Push пока не участвует в production deployment; вернёмся к нему после домена и HTTPS.
