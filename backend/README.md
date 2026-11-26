# Appointment Assistant Backend

Laravel application core for Appointment Assistant.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis
- Laravel Queues backed by Redis
- PHPUnit
- Laravel Boost

## Local setup

### 1. Start infrastructure

PostgreSQL and Redis are provided by the repository-level Docker Compose configuration.

From the repository root:

```bash
docker compose up -d
```

Check that both services are healthy:

```bash
docker compose ps
```

### 2. Configure the development environment

From `backend/`:

```bash
cp .env.example .env
```

Configure the PostgreSQL connection in `.env` using the local development values defined by the repository Docker Compose configuration.

The application expects:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
```

Set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` to match the local PostgreSQL service configuration.

Redis uses the local service exposed by Docker Compose.

### 3. Install and initialize the backend

```bash
composer setup
```

This installs Composer dependencies, generates the application key and runs the development database migrations.

The command assumes the local environment and database connection have already been configured correctly.

## Testing environment

Backend tests use a PostgreSQL database separate from the development database.

Create the testing database once from the repository root:

```bash
docker compose exec postgres \
  psql -U appointment_assistant \
  -d appointment_assistant \
  -c "CREATE DATABASE appointment_assistant_test;"
```

Then, from `backend/`:

```bash
cp .env .env.testing
```

Set:

```env
APP_ENV=testing
DB_DATABASE=appointment_assistant_test
```

`.env.testing` is local-only and is not committed to Git.

Automated tests prepare their required database state through Laravel's testing infrastructure.

## Development

Start the Laravel development server from `backend/`:

```bash
php artisan serve
```

### Conversation worker and scheduler

After running the backend migrations, start one worker and one scheduler in
separate terminals from `backend/`:

```bash
composer dev:worker     # php artisan queue:work redis --timeout=240 --tries=1
composer dev:scheduler  # php artisan schedule:work
```

The CLI PHP runtime needs `pdo_pgsql`, `redis`, and `pcntl`. Use the same `.env`
for inbound processing, the worker, and the scheduler. Keep
`QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`, and point `DB_*`, `REDIS_HOST`
and `REDIS_PORT` at the local Compose services. If you set `REDIS_QUEUE`,
dispatchers and the worker must use the same queue name. For live generation,
set `OPENAI_API_KEY` with `AI_PROVIDER=openai`; automated tests fake the provider.
After changing `.env` or backend code, clear any cached configuration
(`php artisan config:clear`) and restart the worker and scheduler processes.

| Runtime boundary | Default |
| --- | --- |
| AI SDK timeout per provider request | 60 seconds |
| Entire conversation Job and local worker timeout | 240 seconds |
| Redis reservation (`REDIS_QUEUE_RETRY_AFTER`) | 270 seconds |
| PostgreSQL processing and finalization claim lease | 300 seconds |
| Normal attempts per turn (`CONVERSATION_MAX_ATTEMPTS`) | 3 |
| Retry delays after reported failures (`CONVERSATION_RETRY_DELAYS_SECONDS`) | 30 seconds, then 120 seconds |
| Retry delay after a recorded finalization failure (`CONVERSATION_FINALIZATION_RETRY_SECONDS`) | 120 seconds |
| Scheduler interval / `CONVERSATION_RECONCILIATION_BATCH_SIZE` | Every minute / 100 eligible Conversations per tick |

Keep the worker timeout below the Redis reservation and PostgreSQL claim lease;
use the default `240 < 270 < 300` seconds locally. A turn can make several
provider requests for tool use, so the worker can interrupt a turn before the SDK
finishes all its steps. Laravel allows one execution per Job delivery payload;
fresh Jobs from reconciliation use the same durable turn budget in PostgreSQL.

Every minute, `conversation:reconcile` checks PostgreSQL and requeues up to
the configured batch size of eligible Conversations. Redis cache holds the
overlap lock and the temporary scan cursor. Each scan cycle covers a fixed
range of inbound IDs; losing the cursor restarts the scan in PostgreSQL.
Normal processing stops at exhaustion. Finalization can accept a deterministic
reply from the turn's verified Appointment result, or a neutral reply if there
is no result. Finalization does not call the model.

### Inspect and troubleshoot recovery

From `backend/`, use `php artisan schedule:list` to check the schedule and
`php artisan schedule:run` to run one due tick. Keep the worker running to
consume the queued work. `php artisan queue:failed` lists failed Laravel Job
deliveries; the authoritative turn state and attempt budget are in `ai_turns`.

Inspect a turn in the relevant PostgreSQL database. For the local development
database, run `docker compose exec postgres psql -U appointment_assistant -d appointment_assistant`
from the repository root, then replace `123` with the turn ID:

```sql
SELECT id, inbound_message_id, assistant_message_id, state, attempt_count,
       claimed_until, next_attempt_at, failure_classification, failure_reason,
       fallback_used, terminal_at, terminal_reason, diagnostics
FROM ai_turns
WHERE id = 123;
```

- **Committed inbound, no worker activity:** Check the Laravel log for
  `Conversation processing job could not be queued.`, confirm Redis is
  reachable, and check that the worker and scheduler use the same configuration.
  Once both processes are running, the scheduler can rediscover the pending turn.
- **Interrupted worker or retry backoff:** Restart the worker if it exited.
  The earliest unfinished turn for that participant must be due and any claim
  lease must expire before an eligible scheduler scan can recover it.
- **Exhausted or interrupted finalization:** `failure_resolution_pending` and
  `resolving_failure` still need a reply or a durable disposition. Check the
  lease, next attempt time, and `diagnostics.finalization`; restore PostgreSQL
  if persistence is failing. Check `fallback_used` and `assistant_message_id`
  for accepted replies, or `terminal_reason` for `closed_without_response`.
  `diagnostics.attempts` preserves the original failures even after fallback.
- **Scheduler stopped during a scan:** Its overlap lock expires after five
  minutes. If no scan is running, `php artisan schedule:clear-cache` can clear
  a stale lock before the next tick.

Use scheduled reconciliation for recovery; resetting attempt counts, editing
claims, or replaying Appointment writes can bypass safeguards.

## Tests

Run the backend test suite:

```bash
composer test
```

Tests must use a dedicated testing database rather than the development database.
Concurrent test runs need separate databases because the tests rebuild the schema.

To repeat the Redis/PostgreSQL recovery scenarios with provider fakes:

```bash
vendor/bin/phpunit --filter='test_scheduler_(recovers_|exhausts_failed_generation)'
```

The crash tests start and terminate actual Redis workers on isolated test queues
and advance the test clock to lease expiry without waiting in real time.

### Conversation retry lifecycle

| State | Meaning |
| --- | --- |
| `failure_resolution_pending` | Normal processing will not retry; finalization is still needed. |
| `resolving_failure` | A finalization claim owns the turn and cannot authorize AI tools. |
| `closed_without_response` | A durable disposition closed the turn without accepting an assistant message. |

The retry lifecycle migration installs these states and adds retry metadata to
existing AI turns. Pre-S12A turns retain their states and associations; processing,
failed, and completed turns start with one recorded attempt because earlier
attempt totals cannot be reconstructed. Rollback drops the retry metadata, maps
unresolved work to `failed` and clears its claims, and maps turns closed without
a response to `superseded` so they are not reopened.

`claimed_until` is a takeover eligibility threshold, not an authorization deadline.
An unchanged owner may still complete, fail, or perform an authorized operation after
lease expiry. A new claim token, released claim, or replacement Conversation fences
the old owner; elapsed time alone does not. The same rule applies to finalization.

Recoverable reported failures retry after the configured delay measured from failure
recording. For interrupted processing, the failure time is unknown: takeover requires
both lease expiry and the configured minimum spacing since the persisted claim time
(`max(claimed_until, claimed_at + retry_delay)`). The default 300-second lease already
exceeds the default 30/120-second delays, so recovery remains eligible at lease expiry.
Shorter leases or longer configured delays still enforce progressive spacing.
Repeated scans do not restart this wait or consume attempts. Exhausted processing
can enter finalization at lease expiry without another model attempt or generation
backoff.

`conversation.processing.retry_delays_seconds` must be a non-empty list of positive
integer seconds. Invalid configuration falls back to `[30, 120]`, preserving durable
failure recording and a bounded retry budget.

The current single-provider processing path treats provider authentication/permission
errors (HTTP 401/403) and SDK insufficient-credit errors as non-recoverable: another
unchanged request cannot repair credentials, access, or funding. These await
finalization rather than more model attempts. Rate limits, overload, connection
failures, and unknown errors retain bounded recovery. `LogicException` is not a
blanket permanent-failure category: existing identity checks deliberately allow
retry after authoritative identity state is repaired, without exposing protected
context while identity remains invalid.

## Code style

Format backend PHP code with Laravel Pint:

```bash
./vendor/bin/pint
```

## Laravel Boost

Laravel Boost is installed as a development dependency and provides Laravel-specific MCP tooling, guidelines and skills for supported coding agents.

Project-specific backend rules are stored under:

```text
.ai/rules/
```

Generated Boost skills under `.agents/` are not committed to Git.

After a relevant Laravel or Boost update, Boost resources can be regenerated with:

```bash
php artisan boost:update
```

Boost resources do not need to be regenerated during normal slice implementation unless the relevant tooling or dependencies change.
