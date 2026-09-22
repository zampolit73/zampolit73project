# Reading diary

Route: `/projects/reading-diary`

## Purpose

A small personal reading log that looks and behaves like a bookshelf rather than a generic table/dashboard.

## Persistence

The MVP is deliberately browser-local.

- storage: `localStorage`;
- key: `zampolit73.reading-diary.v1`;
- no account is required;
- no reading data is sent to Laravel;
- data does not automatically sync between browsers/devices.

## Built-in book

On first use the shelf contains:

- **Сказки Пушкина**
- author: Александр Пушкин
- no preset rating, date or personal note

The starter entry is protected from deletion. Its detail card links to the separate animated Pushkin project.

## Book fields

Each custom book stores:

- id;
- title;
- author;
- completion date;
- integer rating from 0 to 5;
- personal note;
- starter flag.

## UI

The page provides:

- wooden CSS bookshelf with book spines;
- selected-book cover/detail panel;
- five-star rating control;
- completion date;
- personal note;
- add-book form;
- removal for custom books;
- total/rated/average statistics.

All visual book/shelf artwork is CSS-only.
