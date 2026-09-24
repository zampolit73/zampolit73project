# Рейтинг Коммерсанта

Проект №05 в `/projects`.

Route:

`/projects/kommersant-ranking`

## Назначение

Внутренняя общая рабочая база по рейтингу топ-менеджеров «Коммерсанта» 2026 года.

Исходный workbook:

`ТОП-1000 менеджеры по направлениям 2026 LinkedIn`

Источник, указанный в файле:

- газета «Коммерсантъ», №171 от 17 сентября 2026 года;
- `KOM_171_170926.pdf`.

Проект сохраняет структуру исходных направлений, но рабочее состояние больше не зависит от Excel: после первичного импорта данные живут в production SQLite.

## Исходные данные

Фактический импорт:

- 19 рейтинговых направлений;
- 1120 manager rows;
- 338 исходно заполненных LinkedIn URL;
- 55 LinkedIn-кандидатов на ручную проверку.

Название workbook содержит TOP-1000, но сумма строк 19 рейтинговых вкладок равна 1120. Это не исправляется искусственным удалением строк: приложение показывает фактически импортированный dataset.

Направления в исходном порядке:

1. Высшие руководители — 250;
2. Коммерческие — 100;
3. Финансовые — 100;
4. Маркетинг — 100;
5. Связи с общественностью — 80;
6. Персонал — 80;
7. Развитие — 60;
8. Правовые вопросы — 50;
9. Органы власти — 40;
10. Информационные технологии — 50;
11. Цифровая трансформация — 40;
12. Корп. управление — 30;
13. Устойчивое развитие — 25;
14. Логистика — 20;
15. Кибербезопасность — 25;
16. Закупки — 20;
17. Внутренние коммуникации — 15;
18. Связи с инвесторами — 10;
19. Операционные — 25.

Плюс отдельная вкладка «Кандидаты на проверку».

## Data model

### kommersant_categories

Метаданные вкладки/направления:

- `slug`;
- `label`;
- `title`;
- source note;
- PDF pages;
- newspaper pages;
- position.

### kommersant_managers

Рабочая строка рейтинга:

- category;
- исходный номер строки;
- отрасль;
- место;
- Ф.И.О.;
- LinkedIn URL;
- должность;
- компания;
- страница PDF;
- optional `assigned_to_user_id`;
- optional `assigned_at`.

### kommersant_candidates

Очередь ручной проверки:

- Ф.И.О.;
- компания;
- должность;
- LinkedIn candidate;
- статус / уверенность;
- результат и основание;
- источник / проверка;
- optional responsible user and timestamp.

### kommersant_activities

Короткая общая activity history:

- user;
- manager/candidate subject;
- subject id/name;
- action;
- timestamp.

Сейчас журнал пишется для assignment и LinkedIn changes.

## LinkedIn

LinkedIn редактируется inline в таблице.

Разрешено:

- добавить ссылку;
- заменить ссылку;
- очистить ссылку.

Backend принимает только HTTP/HTTPS URL, у которых host равен `linkedin.com` или заканчивается на `.linkedin.com`.

В исходном workbook текст вроде «ИИ не смог найти» не сохраняется как URL: при импорте это `NULL`.

## Ответственный / «В работе у»

Поведение совпадает по смыслу с CIO Presentations:

- свободную запись можно забрать на себя;
- у записи максимум один ответственный;
- claim выполняется conditional update по `assigned_to_user_id IS NULL`;
- concurrent user не может перезаписать уже занятую строку;
- пользователь может снять только собственный assignment;
- удаление user оставляет manager/candidate, FK становится null;
- filtering поддерживает Free / Mine / Assigned / specific user.

Assignment работает и для основных manager rows, и для кандидатов.

## Вкладки и фильтры

Навигация:

- Обзор;
- 19 направлений;
- Кандидаты на проверку.

Manager filters:

- text search;
- company;
- LinkedIn найден / не найден;
- responsible user.

Candidate filters:

- text search;
- company;
- confidence status;
- responsible user.

Основные таблицы пагинируются по 50 строк.

## Initial import

Dataset хранится под:

`database/data/kommersant-ranking-2026/data-*.txt`

Это gzip-compressed JSON, закодированный base64 и разбитый на текстовые chunks, чтобы исходный workbook не требовался на production.

Migration:

`database/migrations/2026_09_24_080000_create_kommersant_ranking_tables.php`

делает import только в момент создания таблиц.

### Важное ограничение

Не использовать обычный deploy для повторного импорта.

Будущая версия рейтинга не должна молча перезаписывать:

- исправленный пользователем LinkedIn;
- assignment;
- activity history;
- другие рабочие изменения.

Новый год / новый файл требует отдельной import strategy.

## Frontend

Основная страница:

`resources/js/pages/KommersantRanking.vue`

Styles:

`resources/css/kommersant-ranking.css`

Визуально проект следует существующему editorial/poster языку:

- cream;
- near-black;
- deep red;
- сильные рамки;
- offset shadows;
- condensed display headings.

Широкие вкладки и таблицы имеют собственный horizontal scroll container. На mobile нельзя создавать page-level horizontal overflow.

## Access

- guest → `/login`;
- `user` — полный функционал;
- `admin` — тот же функционал проекта плюс отдельные site-admin возможности.

Никакой отдельной project-role для этого инструмента нет.
