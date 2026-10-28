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

## Tests

Run the backend test suite:

```bash
composer test
```

Tests must use the dedicated testing database rather than the development database.

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
