# Vacancy Source

## Purpose

Vacancy Source is project #06 inside `zampolit73project`. Its product goal is to take an IT vacancy and identify likely end clients from Telegram history and public web sources, while preferring an explicit "not enough data" result over a confident false match.

## Current implementation

The first web iteration remains the asynchronous product skeleton:

```text
Web textarea
   |
vacancy_investigations
   |
Laravel database queue
   |
RunVacancyInvestigation
   |
progress stages
   |
Vue polling + history
```

The queued investigation job still does not perform real Telegram-source or web research. Its stages remain explicitly technical/demo so the product never invents a client. On top of that skeleton, Laravel now implements the Telegram Bot user transport and site-account binding.

## Access model

- authenticated `user`: can launch investigations and see only their own history/status;
- `admin`: can launch investigations and see the team's history;
- guests are redirected to login.

Admin review data has a table in the initial schema, but the review UI/actions are a later iteration.

### Telegram binding and Bot input

- `/admin/users` shows Telegram binding state for every site user;
- an admin can generate a one-time code with no automatic expiry;
- issuing a new unused code invalidates the previous unused code for that user;
- only the SHA-256 hash is persisted; plaintext is shown only in the redirect response that created it;
- the user sends `/start CODE` to the bot in a private chat;
- one site user can have one Telegram account and one Telegram identity can be linked to one site user;
- an admin can unlink the binding and later generate a new code;
- group/supergroup bot messages are ignored;
- after binding, ordinary private text or a Forward creates the same `vacancy_investigations` record/queue job as the web form;
- Forward metadata is deliberately ignored; only message text/caption becomes investigation input;
- the current demo worker sends Telegram progress/final messages but still labels them as technical demo output.

## Routes

- `GET /projects/vacancy-source`;
- `POST /projects/vacancy-source/investigations`;
- `GET /projects/vacancy-source/investigations/{id}/status`;
- `POST /projects/vacancy-source/investigations/{id}/cancel`;
- admin: `POST /admin/users/{user}/telegram-invite`;
- admin: `DELETE /admin/users/{user}/telegram-binding`;
- public stateless webhook: `POST /api/telegram/bot/webhook`.

Only a `queued` investigation can be cancelled. Once a worker atomically moves it to `running`, cancellation is rejected.

## Queue

Queue connection: `database`.

Named queue: `vacancy-source`.

Production runs one worker so the small VPS processes at most one investigation at once:

```bash
php8.3 artisan queue:work database --queue=vacancy-source --sleep=1 --tries=1 --timeout=330
```

The job class has a 300-second timeout and one try. Database queue `retry_after` is 360 seconds.

## Initial data model

### vacancy_investigations

Owns:

- user / input source;
- original input text (stored, not shown in normal history);
- future normalization/fingerprint fields;
- queue/run state;
- progress stage/text;
- result summary;
- queue/start/finish/cancel/timeout timestamps.

### investigation_candidates

Reserved for the ranked end-client candidates:

- company;
- direct/indirect type;
- confidence score;
- end-client flag;
- rank;
- explanation.

The demo job does not insert fake candidates.

### investigation_reviews

Reserved for admin quality review:

- correct / incorrect / partial;
- optional corrected client;
- optional confirmation URL;
- notes and reviewer.

## Planned search behaviour

Later iterations should keep these already-agreed product rules:

- search Telegram and web for each full investigation;
- Telegram source is a dynamic folder of roughly 30 work chats;
- initial backfill is three months; new messages sync roughly every five minutes;
- only vacancy-like text messages are stored; no media;
- removed chats stop syncing but historical indexed messages remain;
- edited messages update; deleted messages remain marked deleted;
- strict dedupe/grouping threshold;
- chat title does not contribute to confidence;
- geography contributes zero weight;
- seniority is near-zero weight;
- rare requirements, internal product/system names and rare tech combinations are strong signals;
- contradictory core stack is a strong negative signal;
- confidence is deterministic/heuristic, not an LLM probability;
- display threshold starts around 60%;
- do not force three candidates when fewer are reliable;
- intermediaries/vendors are shown separately from end-client candidates;
- result explanations include the strongest matching fragments and source links;
- web search is two-pass: rare/exact signals first, then broader role + stack + industry if needed;
- Russian and English query variants are generated for key signals;
- one investigation has a five-minute hard ceiling;
- historical admin-confirmed results are hints, not immutable ground truth.

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
2. SQLite FTS5 + normalization + technology aliases.
3. Web search provider abstraction and safe page fetching.
4. Deterministic evidence/scoring and source clustering.
5. Admin review UI and quality metrics.
