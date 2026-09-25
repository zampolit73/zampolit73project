# Vacancy Source

## Purpose

Vacancy Source is project #06 inside `zampolit73project`. It takes an IT vacancy and tries to identify the likely end client from verifiable evidence. Precision is preferred over recall: a clear "not enough evidence" result is better than an invented client.

## Current investigation flow

The production pipeline is asynchronous:

```text
Web textarea or private Telegram Bot message
        |
vacancy_investigations
        |
Laravel database queue: vacancy-source
        |
signal extraction
        |
public web research (Bing RSS SERP)
        |
deterministic evidence scoring
        |
candidates + sources
        |
web live status/history + Telegram result
```

The web part is now real, not a demo. Telegram work-chat history is still the next data source and is not counted as evidence yet.

## Access model

- authenticated `user`: launch investigations and see own history/status;
- `admin`: same investigation functionality plus team history;
- guests: login required.

Admin review storage exists; review UI/actions remain a later iteration.

## Telegram Bot input and binding

- `/admin/users` shows binding state for every site user;
- admin can generate one-time binding codes without automatic expiry;
- only SHA-256 hashes of codes are stored;
- user sends `/start CODE` to the private bot;
- one site user ↔ one Telegram identity;
- group/supergroup bot messages are ignored;
- ordinary private text or Forward text creates the same investigation queue job as the web form;
- Forward metadata is ignored; only message text/caption is research input;
- `/status` returns the latest investigation state or complete formatted result.

Production Bot transport is long polling; see the Telegram section below.

## Routes

- `GET /projects/vacancy-source`;
- `POST /projects/vacancy-source/investigations`;
- `GET /projects/vacancy-source/investigations/{id}/status`;
- `POST /projects/vacancy-source/investigations/{id}/cancel`;
- admin: `POST /admin/users/{user}/telegram-invite`;
- admin: `DELETE /admin/users/{user}/telegram-binding`;
- fallback/test webhook: `POST /api/telegram/bot/webhook`.

Only a queued investigation can be cancelled.

## Queue

Queue connection: `database`.

Named queue: `vacancy-source`.

Production runs one worker:

```bash
php8.3 artisan queue:work database --queue=vacancy-source --sleep=1 --tries=1 --timeout=330
```

The investigation job has a 300-second timeout and one try. Search-provider failures are handled inside the job and can produce a `partial` result instead of fabricating evidence.

## Data model

### vacancy_investigations

Stores:

- owner and input source;
- original vacancy text;
- normalized text and SHA-256 fingerprint;
- queue/run state;
- progress stage/text;
- final summary;
- queue/start/finish/cancel/timeout timestamps.

### investigation_candidates

Stores ranked hypotheses:

- company name;
- `direct` / `indirect` evidence type;
- deterministic confidence score;
- end-client flag;
- rank;
- explanation.

At most three end-client hypotheses are retained above the display threshold. Clear recruitment/outstaff intermediaries are marked `is_end_client=false` and do not occupy end-client slots.

### investigation_sources

Stores the strongest web evidence used by an investigation:

- optional candidate link;
- provider;
- title;
- URL;
- snippet;
- search query;
- evidence score.

The application stores links/snippets only; it does not mirror arbitrary result pages.

### investigation_reviews

Reserved for admin validation:

- correct / incorrect / partial;
- optional corrected client;
- optional confirmation URL;
- notes and reviewer.

## Real web research v1

Current provider: Bing web search RSS output:

`https://www.bing.com/search?...&format=rss`

No paid API key is required.

The service generates up to six compact queries from the vacancy:

1. strongest rare/exact requirement phrases + important technologies;
2. a second rare phrase when available;
3. targeted `site:hh.ru/vacancy` search;
4. targeted `site:career.habr.com/vacancies` search;
5. broader Russian role + stack query;
6. English role + stack variant / explicit company query when useful.

Search results are deduplicated by URL. The first iteration scores the SERP title/snippet only; it deliberately does not treat inaccessible page content as if it had been verified.

### Deterministic confidence

Weights live in `config/vacancy_source.php`.

Strong signals:

- exact rare phrase match;
- rare technology combinations;
- explicit company mention in the input plus corroboration;
- evidence on more than one distinct domain.

Medium/weak signals:

- technology overlap;
- role overlap;
- significant-token overlap.

Explicit product rules remain:

- geography contributes **zero** score;
- seniority contributes effectively zero;
- common stack by itself is weak;
- confidence is a heuristic score, not a calibrated probability;
- default display threshold is 60%;
- fewer than three candidates are shown when evidence is weak;
- probable recruiting/outstaff vendors are separated from end clients.

A candidate is labelled `Прямое совпадение` only when the strongest source has a high evidence score and at least one exact rare phrase. Otherwise it is `Косвенная гипотеза`.

## User-facing result

Web active result shows:

- summary;
- up to three end-client candidates;
- confidence;
- direct/indirect label;
- explanation;
- probable intermediaries separately;
- strongest clickable sources with evidence score and snippet.

Telegram final messages and `/status` use the same persisted candidates/sources and include up to three source links.

If no candidate crosses the threshold, the result explicitly says the end client was not reliably determined.

## Production diagnostics for web research

Deploy runs:

```bash
php8.3 artisan vacancy:web:probe
```

The probe uses a generic non-user vacancy query and prints only provider/result-host diagnostics. Failure is non-fatal for the website deploy, but is visible in Actions so public-search connectivity can be distinguished from application bugs.

## Planned Telegram research corpus

The next major source is a separate Python MTProto Reader for the user's work-folder chats.

Already-agreed behavior:

- dynamic folder whitelist of roughly 30 work chats;
- three-month backfill when a chat is newly added;
- sync around every five minutes;
- text/captions only, no media;
- removed chats stop new sync but historical messages remain;
- edited messages update;
- deleted messages should remain marked deleted;
- strict similarity clustering to avoid counting reposts as independent evidence;
- chat title is display metadata, not a confidence signal;
- one shared Telegram user account for MVP, replaceable later without changing Laravel investigation semantics.

After the Reader is connected, Telegram and web evidence will be searched in parallel and combined by the deterministic scoring layer.

## Telegram production transport

Production uses **Bot API long polling**, not webhook delivery.

Why:

- the VPS DNS currently resolves `api.telegram.org` to `149.154.166.110`, and TCP/TLS to that address times out;
- the same VPS can reach Telegram Bot API IPv4 `149.154.167.220` normally;
- host firewall is not the cause: UFW is inactive and iptables INPUT/OUTPUT policies are ACCEPT;
- GitHub/Cloudflare control HTTPS probes from the VPS succeed.

The deploy therefore stores:

```text
TELEGRAM_BOT_API_IP=149.154.167.220
TELEGRAM_BOT_PUSH_ENABLED=true
```

`TelegramBotClient` preserves the hostname `api.telegram.org` for TLS/SNI but pins the connection to `TELEGRAM_BOT_API_IP` with cURL resolve options.

A dedicated systemd process runs:

```bash
php8.3 artisan telegram:bot:poll
```

The poller:

- disables the Telegram webhook without dropping pending updates;
- long-polls `getUpdates`;
- persists the last processed update ID in Laravel's persistent cache;
- routes each update through the same `TelegramUpdateHandler` used by the webhook fallback;
- sends replies and progress/final messages through the Bot API on the working pinned IPv4.

The stateless webhook route `POST /api/telegram/bot/webhook` remains in the application as a fallback/test transport, but production Telegram delivery is polling-based.

## Telegram production configuration

The repository contains no BotFather token. Production activation requires one GitHub Actions repository secret:

```text
TELEGRAM_BOT_TOKEN=<fresh BotFather token>
```

On a main deploy, Actions transfers the token to the VPS through a short-lived protected file and writes it to the persistent shared `.env`. The token is never committed. `TELEGRAM_BOT_USERNAME` is optional and only improves the admin deep-link UX.

The deploy also asks Telegram to delete any existing webhook with `drop_pending_updates=false`, so pending messages are preserved for the long poller.

## Telegram diagnostics

`php8.3 artisan telegram:bot:diagnose` prints safe aggregate state plus network diagnostics without exposing bot tokens, webhook secrets, Telegram user IDs or chat IDs.

Current diagnostics include:

- linked / used / unused binding counts;
- default route and host firewall policy;
- DNS A records for `api.telegram.org`;
- TCP/TLS checks to the DNS-returned Telegram IP and the known working alternate Bot API IP;
- control HTTPS checks to GitHub and Cloudflare;
- a real Bot API `getMe` probe through the configured pinned API IP.

## Next implementation steps

1. Python Telegram Reader with the user's MTProto session and Telegram folder sync.
2. SQLite FTS5 index for the Telegram corpus plus strict repost clustering.
3. Combine Telegram and web evidence in one scoring pass.
4. Add safe page-level corroboration for selected web sources beyond SERP snippets.
5. Admin review UI and quality metrics.
