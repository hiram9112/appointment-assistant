# ConversationAgent evaluations

These **[Developer]-operated**, paid OpenAI evaluations exercise the existing
`AiTurnCoordinator → ConversationTurnProcessor → ConversationAgent → application
capabilities` path. They do not create another agent, use SDK conversation storage,
or deliver WhatsApp messages.

**Coding agents must not execute live requests.** They implement and run the
offline PHPUnit coverage; the Developer runs live conversations, checks their
meaning against PostgreSQL, reviews the implementation, and accepts the slice.
Neither an HTTP success nor a passing automated contract constitutes acceptance.

## Suites

| Suite | Files | Dedicated database | Private evidence directory |
| --- | --- | --- | --- |
| Descriptive/availability regressions (introduced for S11) | `ConversationAgentEvaluationRunner.php`, `run-live.php` | `appointment_assistant_s11_smoke` | `backend/storage/app/private/s11-smoke/` |
| Appointment operations (S12 Task 7) | `AppointmentOperationScenarios.php`, `AppointmentOperationsEvaluationRunner.php`, `run-appointment-operations.php` | `appointment_assistant_appointment_evaluation` | `backend/storage/app/private/appointment-operations-evaluation/` |

`EvaluationProviderGuard.php` shares endpoint, retry, timeout, storage and usage
controls between both suites. The Appointment suite also reuses the existing
database isolation guard and reference-data seed. Ordinary PHPUnit tests fake
provider **HTTP**, retaining real SDK parsing, real tools and PostgreSQL writes.
The evaluation classes themselves live outside PHPUnit's suite directories.

## Configuration and setup — [Developer]

Install backend dependencies, configure `backend/.env`, and start local PostgreSQL.
Set `OPENAI_API_KEY` and `OPENAI_MODEL` in that local file. Never put credentials
in command arguments or committed files. Both CLIs require:

- `APP_ENV=local`, uncached Laravel configuration;
- PostgreSQL on `127.0.0.1`, `localhost` or `::1`, without read/write host overrides;
- credentials allowed to migrate/reset the dedicated database;
- the official OpenAI base URL, `https://api.openai.com/v1`.

The configured ordinary database name/URL is **not** the reset target. The guard
selects only the two allowlisted evaluation database names, clears URL overrides,
fixes the search path to `public`, and verifies `current_database()` before reset.
No development, testing or remote database is an acceptable reset target.

From the repository root:

```bash
docker compose up -d postgres
php backend/artisan config:clear
```

Create each dedicated database **once**, skipping a command if it already exists:

```bash
docker compose exec postgres psql -U appointment_assistant -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE appointment_assistant_s11_smoke OWNER appointment_assistant;'
docker compose exec postgres psql -U appointment_assistant -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE appointment_assistant_appointment_evaluation OWNER appointment_assistant;'
```

**Every invocation destructively resets its suite's dedicated database**, migrates
it and seeds synthetic Lúmina reference data, including the eight demo bookings.
Do not store anything valuable there. A suite-specific nonblocking file lock
prevents overlapping resets/runs. Do not reset or modify a running evaluation's
database from another process.

### Descriptive/availability suite

Both flags and their order are mandatory:

```bash
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-live.php --live --reset-smoke-database
```

The twelve existing scenarios cover colloquial/ambiguous Service references,
duplicate Professional names, configured and missing Business facts, real S03
availability, relative dates, exact-start enquiries, ordered references,
Professional preferences, changed topics, continuity, changing configuration,
and the distinction between opening hours and availability.

Operation time is frozen at **9 November 2026, 00:30 Europe/Madrid** (UTC Sunday).
Each scenario has its own Conversation; configuration overlays are restored.
Fixture preflight precedes paid requests. Descriptive and availability gates
(scenarios 3 and 4) run first; a failed gate stops subsequent scenarios.
Other completed contract failures are recorded while remaining scenarios continue.

### Appointment-operations suite

Run **one selected scenario per invocation**. There is deliberately no implicit
`all` mode or automatic rerun. Both safety flags, their order and a known scenario
name are mandatory:

```bash
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-appointment-operations.php --live --reset-evaluation-database --scenario=registered-lifecycle
```

`--help` is safe without bootstrap or database access. Unknown scenario names are
rejected before Laravel bootstrap. Repeat the command with each name below, but
**review/save the evidence and inspect PostgreSQL before the next reset**.

| Scenario name | Conversation / Developer review focus |
| --- | --- |
| `registration-continuity` | Unknown identity, first name/surname, optional-phone refusal, registration-to-booking continuity in one Conversation, then listing. |
| `registered-lifecycle` | Listing, creation, rescheduling with retained Professional, explicitly requested Professional change, cancellation. Four separate proposals and later confirmations. |
| `negative-reply` | Explicit refusal rejects the proposal; a later yes cannot revive it. |
| `ambiguous-reply` | Ambiguity asks for clarification without execution; later explicit approval can execute. |
| `changed-selections` | Conditional yes changes the time; then changes Professional and date. Old proposals are rejected and only the latest is confirmed. |
| `premature-reply` | Same-turn yes is insufficient. The harness additionally persists a yes **after preparation but before assistant acceptance**; that queued message also cannot execute. |
| `occupied-slot` | A separate synthetic Customer authoritatively books the slot after presentation. Confirmation rejects with `slot_unavailable`; an alternative needs a fresh question/confirmation. |
| `ambiguous-appointments` | Two existing Appointments require clarification before cancellation; inspect Customer/Business-scoped listing. |
| `mixed-intent` | Answer the Service-price question (€55) and request confirmation coherently in the same preserved model response. |
| `prepared-provider-recovery` | Inject interruption immediately after persisted preparation, before a final response; deliberately resume the **same turn** once. |
| `prepared-assistant-recovery` | Inject assistant-insert failure: proposal remains prepared without an accepted question, then recover the same immutable proposal. |
| `completed-provider-recovery` | Inject interruption after authoritative creation/result commit; resume the confirming turn without a second operation/audit. A later “Sí, otra vez.” must preserve the known success and clarify intent, not authorize another booking. |
| `completed-assistant-recovery` | Inject assistant-insert failure after authoritative creation; recover the durable result rather than infer success from old prose. The later repeated affirmative must not contradict the accepted recovery response or reactivate the proposal. |
| `new-conversation` | Advance the clock 12 hours. No old proposal/history is inherited; persistent Customer identity and existing Appointments remain. |
| `tomorrow` | At Madrid Monday 00:30, mañana means Tuesday 10 November, even though UTC is still Sunday. |
| `weekday` | Resolve el jueves to 12 November, retaining explicitly selected Sara. |
| `month-boundary` | Frozen at 30 November: mañana resolves to 1 December. |
| `spring-dst` | Frozen at 28 March: Madrid 29 March 02:30 is nonexistent; clarify, then prepare explicitly selected 03:30 at `+02:00`. |
| `autumn-dst` | Frozen at 24 October: explicitly selected **second** 25 October 02:30 uses `+01:00`, not `+02:00`. |
| `unchanged-reschedule` | Explicit “sin cambiar ningún dato”: verify the existing Appointment with a scoped read and acknowledge no changes, without a proposal, execution, or audit. Technical `unchanged` execution is tested deterministically, not forced by this prompt. |
| `information-regression` | Business address/payments/free consultation, followed by informational availability, without Appointment mutation. |

Unless noted otherwise, operation time matches the existing reference week.
Sunday Business/Laura hours `01:00–06:00` are synthetic, recorded overlays for the
two DST scenarios, not a change to the reference Business or production behavior.
Existing target Appointments are synthetic fixtures, not audited operations;
subsequent application operations must have the appropriate audits.

The scripts provide fixed bounded Customer messages, not an LLM customer
simulator. Expected operation IDs/arguments are internal rubric data, **not**
instructions injected into the model. The model still chooses tools and writes
all customer-facing wording. Preparation preflight uses real scheduling
capabilities (own-Appointment exclusion for fixture rescheduling) without
preparing or mutating proposals. Reschedules of a booking created during the
scenario are checked against persisted state after that booking exists, not by
inventing a fixture ID.

A contract failure stops the scenario before dependent prompts; later turns
remain only in `planned_turns`. Missing expected operations, unexpected writes,
wrong parameters, truncation and persistence discrepancies are failures, even
when the transcript sounds plausible. Additional harmless read calls and denied
premature/repeated confirmations (`confirmation_required` / `no_pending_proposal`)
may occur.
Omitting the Professional on a reschedule and explicitly passing the same current
Professional are equivalent; silently switching to another Professional is not.
An unexpected clarification can require investigation of the scenario/response;
do not treat an incomplete flow as acceptance or relax valid assertions merely
to obtain a pass.

Recovery interruptions are **local fault injections**, not simulated successful
provider responses and not real provider outages. Only the deliberately injected
exception is resumable by the harness. Unrelated HTTP, database and provider
failures stop the run. A planned recovery is one explicit retry of the same
persisted turn, with a new request allowance but the same cumulative run budget;
it is not an automatic provider retry.

## Cost and safeguards

| Limit | Descriptive/availability | Appointment operations (per selected scenario) |
| --- | ---: | ---: |
| HTTP attempts | 48 | 64 |
| HTTP attempts per turn/explicit recovery attempt | 3 | 8 |
| Output tokens/request | 512 | 1,024 |
| Output tokens/request for `gpt-6-luna` | 2,048 | 4,096 |
| Request bytes | 24,000 | 64,000 |
| Cumulative request bytes | 750,000 | 2,000,000 |
| Reported input + output tokens | 160,000 | 200,000 |
| Request / connect timeout | 60s / 10s | 60s / 10s |

The token threshold is checked **before the next request**; one response may cross
it, and failures may not report usage. Luna reasoning shares its output limit.
Only POSTs to the official `/v1/responses` endpoint are allowed; redirects and
provider storage are disabled. Identical request retransmissions are blocked.
The SDK's short `Retry-After` retry path is disabled process-locally.
No failover provider or SDK Conversation store is used.

These are technical usage bounds, **not a monetary spending guarantee**. Set a
provider-side spending limit and review model pricing. Each scenario invocation
or manual rerun incurs fresh charges. Do not increase limits silently to hide
truncation or looping.

Evidence directories are Git-ignored, created with mode `0700`; atomically
replaced JSON files use `0600`. Evidence includes synthetic prompts, responses,
tool arguments/results, runtime context, history, usage and review rubrics.
Appointment evidence also contains before/after Customer, identity, Conversation,
proposal, Appointment, audit, message and turn snapshots (without claim tokens),
plus injected-failure/competing-booking details. Keep it private and out of commits.
The configured API key is redacted; provider exception messages, request headers
and stack traces are not printed or saved. Use only synthetic fixture data.

## Result interpretation and acceptance — [Developer]

- `completed_needs_review` (exit 0): structural contracts passed. Every scenario
  and response still requires **human semantic review**; `developer_review` stays
  `pending` and turn review status stays `unreviewed`.
- `contract_failures` (exit 2): inspect failed `checks`, expected arguments,
  actual tools and persisted records. Appointment scenarios stop at first failure.
- `stopped_at_integration_gate` (exit 2, descriptive suite only): later scenarios
  were not run after a failed initial gate.
- `stopped` (exit 2): fixture, provider, technical or budget interruption. Inspect
  the category and partial evidence; do not automatically retry.
- Exit 1: invalid opt-in/unknown scenario/setup/reset failure; evidence may not
  exist. No success or rejection is implied by a technical failure.

For **each** Appointment-changing interaction:

1. Compare the assistant's proposed **operation, Service, Professional, local
   date/time and original/proposed details** with the persisted proposal
   `operation`, `payload`, `presentation` and captured `persisted_proposal` tool
   evidence. Check exact UTC instants, requested-date offsets and timezone,
   especially DST, weekdays and month boundaries. Inspect natural Spanish wording:
   concise/professional, readable dates, no unnecessary technical IDs/timestamps.
2. Check the question is genuinely requesting confirmation, not claiming the
   Appointment has already changed or asking permission to prepare a proposal
   that is already prepared. It should authorize execution of the booking,
   rescheduling or cancellation (for example, “¿Confirmas esta reserva?”).
   Verify mixed-intent information was retained.
   `pending` proves accepted persistence, **not** semantic correctness or delivery.
3. Verify the actual assistant message referenced by
   `confirmation_request_message_id` belongs to `originating_turn_id`; a confirming
   turn's inbound message ID must be **later**. Check declined, ambiguous,
   conditional, premature and replaced proposals did not cause a domain mutation.
4. Compare completed/rejected `result`, `confirming_turn_id`, Appointment state and
   audits. Look for premature success claims, misreported conflicts, silent
   Professional substitutions, duplicate mutations/audits, or an unchanged
   reschedule presented as a change.
5. For prepared recovery, compare proposal **ID, payload and presentation** before
   and after retry; confirm the new wording used saved context without preparation
   or availability replay. For completed recovery, confirm the exact durable result
   was supplied before generation, with no repeated Appointment operation.

### Corrective follow-up — [Developer]

From the repository root, rerun these scenarios **one at a time**, inspecting and
saving each run's evidence and PostgreSQL records before the next command resets
the dedicated evaluation database:

```bash
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-appointment-operations.php --live --reset-evaluation-database --scenario=completed-assistant-recovery
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-appointment-operations.php --live --reset-evaluation-database --scenario=completed-provider-recovery
APP_ENV=local php backend/tests/Evaluations/ConversationAgent/run-appointment-operations.php --live --reset-evaluation-database --scenario=unchanged-reschedule
```

Use the setup and provider spending safeguards above. Retain the initial run's
historical JSON unchanged; these commands generate separate, new evidence.

For both completed recoveries, inspect the final separate “Sí, otra vez.” turn:
the accepted recovery response must be present in completed history. The
turn-local `appointment_operation_result` and `active_proposal` are absent/null
on that later turn by design, not evidence of booking failure. The assistant must
not contradict the known booking outcome or treat ambiguity as authorization for
a second booking. If a current-state check is needed, it must use
`GetCustomerAppointmentsTool`, not availability as a proxy for booking success.
Confirm one completed proposal, one created Appointment and one creation audit,
with no proposal reactivation or new operation. Check the initial prepared
question asks to execute the reservation, not to prepare it again.

For `unchanged-reschedule`, the explicit request now starts with the no-change
message and requires a scoped Appointment read and **zero writes**. Expect no
proposal or confirmation exchange; verify the target's complete state and audits
remain unchanged. This conversational acknowledgement is not the technical
`unchanged` execution result. `ConfirmAppointmentProposalTest` independently
prepares the existing Appointment's own time against real PostgreSQL, enforces
prepared/pending and later-message authorization boundaries, executes the stored
proposal to `unchanged`, and checks no Appointment save or audit, durable recovery,
scope/ownership revalidation and repeated-confirmation idempotency.

If reviewing adjacent flows, run the same CLI separately with
`--scenario=registered-lifecycle`, `--scenario=mixed-intent`,
`--scenario=ambiguous-reply`, `--scenario=premature-reply`,
`--scenario=prepared-provider-recovery` and
`--scenario=prepared-assistant-recovery`. Review booking/rescheduling/cancellation
wording and unchanged response preservation against persisted details. Offline
fakes do not prove model semantics; final S12 acceptance remains a Developer
decision, with no roadmap update as part of this corrective pass.

Inspect PostgreSQL **before the next run resets it**. For example:

```bash
docker compose exec postgres psql -U appointment_assistant -d appointment_assistant_appointment_evaluation
```

```sql
SELECT id, customer_id, channel, sender_identity, last_activity_at FROM conversations ORDER BY id;
SELECT id, first_name, last_name, phone FROM customers ORDER BY id;
SELECT customer_id, channel, external_id FROM channel_identities ORDER BY id;
SELECT id, conversation_id, originating_turn_id, operation, state, payload, presentation,
       confirmation_request_message_id, confirming_turn_id, result
FROM appointment_proposals ORDER BY id;
SELECT id, conversation_id, role, content FROM conversation_messages ORDER BY id;
SELECT id, inbound_message_id, state, assistant_message_id FROM ai_turns ORDER BY id;
SELECT id, customer_id, service_id, professional_id, starts_at, ends_at, status
FROM appointments ORDER BY id;
SELECT id, appointment_id, action, actor_type, actor_id, source, before_state,
       after_state, late_notice FROM appointment_audits ORDER BY id;
```

Database timestamp display may use UTC; compare instants and proposal
Business-local presentation deliberately. Provider prose alone is never proof of
successful registration, execution, rollback or audit persistence.

Record sanitized pass/fail findings with run ID, scenario, model, failed check or
material semantic discrepancy, and relevant persisted IDs/instants. Do not commit
private transcripts or credentials. Material discrepancies are defects: investigate
using ordinary instructions, tool contracts and focused tests. Do **not** add a
deterministic confirmation renderer, an LLM judge or a semantic-validation service.

Run **both suites**, review all marked Task 7 scenarios, review the implementation,
and explicitly accept S12. Only **after Developer acceptance** may the roadmap
`docs/plans/v1/v1.md` be updated. The Developer controls Git; coding agents must
neither mark acceptance nor commit.

## Offline automated verification — coding agent

From `backend/`, using the configured dedicated **test** PostgreSQL database:

```bash
php artisan test --compact --filter='AppointmentOperationsEvaluationRunnerTest|ConversationAgentEvaluationRunnerTest'
php artisan test --compact --filter='RegisterCustomerTest|ResolveCustomerTest|CreateAppointmentTest|GetCustomerAppointmentsTest|RescheduleAppointment|CancelAppointmentTest|AppointmentOccupancyIntegrationTest|Conversation|AiTurn|AppointmentProposalsTest|PrepareAppointmentProposalTest|ConfirmAppointmentProposalTest'
composer test
vendor/bin/pint --dirty --format agent
composer lint
```

CI requires the full backend tests and Pint lint; no separate static analyzer is
configured. PHP syntax checks may also be run on changed files. The full suite
includes the real PostgreSQL integration/concurrency tests for Tasks 1–6,
including savepoints, exclusion conflicts, rollback, stale claims and recovery.
Do not run database-mutating test suites concurrently against the same test DB.

Offline evaluation tests script HTTP responses, **not** language intelligence:
they prove SDK/tool wiring, canonical proposal data, untouched model-response
persistence, message association, recovery context, ordering, scoped reads,
authoritative outcomes/audits, usage ceilings and opt-in safety. They deliberately
do not assert Spanish semantics or real-model accuracy.

## Extending evaluations

Add descriptive scenarios using the existing `scenarios()` convention.
Add Appointment scenario names, explicit prompts/contracts and a human rubric in
`AppointmentOperationScenarios`; extend the runner only for necessary local
fixture/failure controls. Keep descriptive filenames, synthetic data, fixed
allowlisted database targets and bounded request budgets. Update offline tests
and this README, run the applicable full validation, then leave live execution
and acceptance to the Developer.
