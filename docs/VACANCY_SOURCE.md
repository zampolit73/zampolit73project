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

## Telegram production configuration

The repository contains no BotFather token. Production becomes live only after `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` and `TELEGRAM_BOT_WEBHOOK_SECRET` are written to the shared server `.env` and `php8.3 artisan telegram:bot:set-webhook` succeeds. Until then, the admin UI may generate codes but Telegram cannot deliver `/start` or vacancy messages to Laravel.

## Next implementation steps

1. Configure the real production bot credentials/webhook outside Git.
2. Python Telegram Reader with the user's MTProto session and Telegram folder sync.
3. SQLite FTS5 + normalization + technology aliases.
4. Web search provider abstraction and safe page fetching.
5. Deterministic evidence/scoring and source clustering.
6. Admin review UI and quality metrics.
