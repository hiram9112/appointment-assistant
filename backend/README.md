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

In a separate terminal, run one conversation worker from `backend/` with
`composer dev:worker`. Keep `QUEUE_CONNECTION=redis` and make sure Redis is running.
The CLI PHP runtime needs the `redis` and `pcntl` extensions.
The AI SDK allows up to 60 seconds per provider request. The Job caps the entire
turn (which may make multiple requests) at 240 seconds, below the default
270-second Redis reservation and 300-second PostgreSQL claim lease. Keep
`REDIS_QUEUE_RETRY_AFTER` above the Job timeout. The worker does not retry a Job
on failure; PostgreSQL retains the turn for scheduled recovery.

Run Laravel Scheduler from `backend/` in another terminal:

```bash
php artisan schedule:work
```

Every minute, `conversation:reconcile` checks PostgreSQL and requeues up to
100 eligible Conversations (`CONVERSATION_RECONCILIATION_BATCH_SIZE`).
Keep `CACHE_STORE=redis` so scheduler processes share the overlap lock and
the temporary scan cursor. Each scan cycle covers a fixed range of inbound IDs
so new arrivals cannot delay a return to older work indefinitely. Losing the
cursor restarts the scan in PostgreSQL.
Use `php artisan schedule:run` to run one scheduler tick locally.
Exhausted turns remain discoverable without another AI attempt; deterministic
finalization is not yet implemented.

## Tests

Run the backend test suite:

```bash
composer test
```

Tests must use the dedicated testing database rather than the development database.

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
