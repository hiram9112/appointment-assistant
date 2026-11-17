# ConversationAgent live evaluations

This Developer-operated tool evaluates real conversational responses, tool calls and authoritative availability using the existing application path. Its initial twelve scenarios originated in S11. Live evaluations use a paid LLM and require response-quality review; they are **not** PHPUnit tests. The offline `ConversationAgentEvaluationRunnerTest` fakes provider HTTP and runs in the normal backend test suite.

Repository `AGENTS.md` reserves live evaluation for **[Developer]**. Coding agents must not execute live requests.

## Setup and execution

Install backend dependencies, configure Laravel's `backend/.env`, and start local PostgreSQL. Set `OPENAI_API_KEY` and `OPENAI_MODEL` in `backend/.env`; do not put credentials in command arguments or commit them. The runner requires `APP_ENV=local`, an uncached Laravel configuration, loopback PostgreSQL credentials, and the official OpenAI endpoint.

From the repository root, start the service:

```bash
docker compose up -d postgres
```

Create the dedicated database **once**; skip this command if it already exists:

```bash
docker compose exec postgres psql -U appointment_assistant -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE appointment_assistant_s11_smoke OWNER appointment_assistant;'
```

**Every live run destructively resets `appointment_assistant_s11_smoke`**, then migrates and seeds it with synthetic Lúmina reference data. Do not store anything valuable in that database. The runner verifies the connection and database name before reset. Both flags and their order are mandatory:

```bash
php backend/artisan config:clear
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-live.php --live --reset-smoke-database
```

The first two checks are descriptive and availability integration gates. A failed gate stops subsequent scenarios. Fixture preflight precedes all API calls. Technical, provider or budget failures stop the run without automatic retries. Other completed contract failures are recorded while remaining scenarios continue.

The initial scenarios freeze operation time at 2026-11-09 00:30 Europe/Madrid and use the reference demo week, including its Appointments and scheduling exceptions. Each scenario has its own Conversation; temporary configuration overlays are restored between scenarios.

## Cost, safety and evidence

The runner permits at most 48 HTTP attempts, three per turn, 24,000 request bytes each and 750,000 total request bytes. It stops before the next request after 160,000 reported input plus output tokens; a request can cross that threshold and failures may not report usage. The output cap is 2,048 tokens per `gpt-6-luna` request and 512 for other models. Luna's reasoning tokens share that limit. Requests have a 60-second timeout and 10-second connect timeout; redirects, provider storage and automatic retries are disabled.

These are technical usage limits, **not a guaranteed monetary budget**. Charges depend on the model, provider prices and usage. Set an appropriate provider-side spending limit; each rerun incurs new charges.

The printed evidence path is under `backend/storage/app/private/s11-smoke/` (Git-ignored; directory mode `0700`, files `0600`). It contains synthetic prompts, model responses, tool arguments/results, S03 comparisons, usage and review rubrics. Keep evidence private and out of commits; do not copy sensitive transcripts or credentials into documentation. The runner redacts the configured API key from evidence and does not print credentials, provider exception messages or transcripts.

## Interpret results

- `completed_needs_review`: all automated contracts passed, but a Developer must still review every response against its rubric; it is **not** automatic acceptance.
- `contract_failures`: the run completed with at least one failed automated contract. Inspect the relevant expected and actual tool arguments, results, steps and checks.
- `stopped_at_integration_gate`: an initial descriptive or availability contract failed; later scenarios were not run.
- `stopped`: a fixture, provider, technical or budget error interrupted execution. Inspect the saved category and partial evidence before deciding whether to rerun. Exit code 1 indicates setup failure.

The Developer records sanitized pass/fail findings after reviewing response quality. If a failure points to missing persistent tool context, follow the active slice's approval rules before changing persistence.

## Extend the scenario set

Add scenarios in `ConversationAgentEvaluationRunner::scenarios()` with explicit prompts, expected tool arguments/statuses and a human review rubric. The runner executes all defined scenarios in order after the two initial gates. Update offline test expectations as needed; retain fixture preflight, isolation and budget controls. Use synthetic data, preserve existing scenarios unless an approved plan changes them, and run the offline test and full backend suite before a Developer conducts a live evaluation.
