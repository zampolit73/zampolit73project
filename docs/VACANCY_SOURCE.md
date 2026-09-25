# Vacancy Source

## Purpose

Vacancy Source is project #06 inside `zampolit73project`. Its product goal is to take an IT vacancy and identify likely end clients from Telegram history and public web sources, while preferring an explicit "not enough data" result over a confident false match.

## First implemented iteration

The current code is intentionally only the asynchronous product skeleton:

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

The demo job does not search Telegram or the web. It labels every stage as technical/demo and completes with a summary saying that real research is not connected yet. This is deliberate: the first deployment validates queueing, ownership, live progress, cancellation and history before external integrations are introduced.

## Access model

- authenticated `user`: can launch investigations and see only their own history/status;
- `admin`: can launch investigations and see the team's history;
- guests are redirected to login.

Admin review data has a table in the initial schema, but the review UI/actions are a later iteration.

## Routes

- `GET /projects/vacancy-source`;
- `POST /projects/vacancy-source/investigations`;
- `GET /projects/vacancy-source/investigations/{id}/status`;
- `POST /projects/vacancy-source/investigations/{id}/cancel`.

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

## Next implementation steps

1. Telegram invite/binding and Bot webhook.
2. Python Telegram Reader with the user's MTProto session and Telegram folder sync.
3. SQLite FTS5 + normalization + technology aliases.
4. Web search provider abstraction and safe page fetching.
5. Deterministic evidence/scoring and source clustering.
6. Admin review UI and quality metrics.
