# zampolit73project

Стартовый стенд для автоматического деплоя на Ubuntu VPS.

## Схема

GitHub `main` → GitHub Actions → SSH → AdminVPS → Docker Compose → Caddy.

После деплоя проект размещается на сервере в:

```
~/apps/zampolit73project
```

## GitHub Actions secrets

В репозитории должны быть заданы:

- `VPS_HOST` — IP/hostname VPS
- `VPS_USER` — SSH-пользователь
- `VPS_PWD` — SSH-пароль

Не добавляйте реальные секреты, пароли или приватные SSH-ключи в файлы репозитория.

## Проверка

После успешного деплоя:

```
http://<VPS_HOST>/
http://<VPS_HOST>/healthz
```

`/healthz` должен вернуть `ok`.

## Следующий этап

После проверки транспорта этот контейнер можно заменить на приложение на Python, PHP, Go, Node.js, React/Vue или несколько сервисов в одном Compose.
