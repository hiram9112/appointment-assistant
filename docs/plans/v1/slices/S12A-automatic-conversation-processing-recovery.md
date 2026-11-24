# S12A — Automatic conversation processing and recovery

**Status:** Pending — implementation plan awaiting Developer approval  
**Depends on:** S10, S11, S12  
**Covers:** FR-04; FR-06; FR-07; FR-08; V1 operational readiness (cross-cutting)

## Goal

Automatically process persisted inbound conversational work and recover safely from interrupted or failed AI processing without requiring another Customer message.

Preserve the existing Conversation, AI-turn, proposal, Appointment, and audit guarantees. A committed Appointment operation must remain authoritative even when the corresponding assistant response cannot be generated or persisted.

## Expected outcome

- Committed inbound messages initiate asynchronous processing automatically.
- Eligible incomplete turns are recovered without manual intervention or additional Customer messages.
- Processing attempts are bounded and tracked durably.
- Exhausted generation produces an authorized deterministic response when possible.
- Unresolved failures remain persistent and diagnosable.
- Recovery never duplicates Appointment mutations, audits, or accepted assistant responses.

---

## 1. Existing implementation and relevant constraints

S10–S12 already provide durable Conversation messages and AI turns, participant-scoped processing, claim ownership, stale-attempt fencing, atomic assistant acceptance, prepared-proposal recovery, and replay-safe committed Appointment results.

The S12A investigation confirmed these mechanisms work when processing is explicitly re-entered. It also found that automatic dispatch, periodic reconciliation, persistent retry budgets, and diagnosable exhaustion are not yet implemented.

Before implementation, inspect the current relevant code and verify these assumptions against the repository. Reuse existing coordination and persistence mechanisms rather than duplicating them.

### Applicable architectural decisions

- **ADR-0001:** Maintain the pragmatic Laravel modular monolith.
- **ADR-0002:** PostgreSQL remains authoritative for durable application state.
- **ADR-0004:** Laravel AI SDK handles model integration; the AI provider does not own Business operations.
- **ADR-0005:** Use Laravel Queues backed by Redis.
- **ADR-0006:** Keep n8n responsible for selected external integrations, not internal conversation orchestration.

Read the relevant `FR` sections identified by `Covers`, applicable architecture sections, ADRs, and repository instructions. Do not expand Product or Business rules.

---

## 2. Design decisions

### 2.1. Processing and completion boundaries

Keep three concerns distinct:

1. **Business operation completion:** The authoritative Appointment operation, audit, and execution outcome have committed.
2. **Internal conversation completion:** The accepted assistant message and AI-turn completion have committed.
3. **External delivery:** A messaging provider has delivered or acknowledged the outbound response.

S12A implements the second boundary and the asynchronous processing required to reach it. Appointment execution remains governed by existing S12 rules; external delivery belongs to S13.

Never hold a PostgreSQL transaction open across an AI provider call. Never roll back or re-execute a committed Appointment because response generation or persistence subsequently fails.

### 2.2. Asynchronous execution

Use one thin Laravel Job backed by Redis that invokes the existing authorized conversation-processing entry point.

- Dispatch processing after the inbound message and logical turn have committed, using after-commit semantics.
- Pass only stable processing identifiers or authoritative references; retrieve current state from PostgreSQL at execution time.
- Do not serialize AI context, proposal payloads, generated answers, or trusted authorization into the Job.
- Preserve a successful inbound database commit if Redis dispatch subsequently fails. Record the failure and leave the turn discoverable by reconciliation.
- Treat duplicate Jobs, completed turns, active claims, superseded Conversations, and not-yet-eligible work as ordinary control outcomes.
- Continue eligible participant backlog processing after successful completion, using bounded execution or continuation Jobs rather than an unbounded worker loop.

Begin with one worker. Additional workers, queues, distributed locks, and scaling mechanisms are not required.

### 2.3. Automatic reconciliation

Use Laravel Scheduler to execute a bounded reconciliation operation once per minute.

PostgreSQL is the durable ledger of internal work. The reconciler must identify:

- committed pending turns with missing or lost dispatch;
- recoverable failed turns whose retry time has arrived;
- processing claims whose leases have expired;
- exhausted turns requiring deterministic finalization or safe terminal disposition after an interruption.

Dispatch eligible work through the same Job pathway.

Respect existing Conversation ownership, participant FIFO ordering, claim fencing, retry eligibility, and supersession rules. Use bounded, deterministic batches.

Concurrent scans and repeated dispatches must remain safe.

Do not introduce a separate outbox, generalized workflow engine, or second authoritative queue for internal conversation processing.

### 2.4. Persistent retry policy

Use one retry budget per logical AI turn, persisted in PostgreSQL.

The initial policy is:

- Maximum of **three authorized processing attempts**, including the first.
- Progressive backoff for recoverable failures.
- Configurable limits and delays.
- No automatic reset of attempts after Job duplication, Redis redelivery, scheduler execution, or worker restart.

Count an attempt atomically when the existing coordinator successfully grants an authorized processing claim.

A `Busy` result, duplicate trigger, already completed turn, or reconciliation scan does not consume an attempt. An authorized attempt interrupted by a process crash does count.

Laravel Queue manages Job execution and delivery. It must not introduce a separate, unbounded AI-processing retry budget.

Distinguish:

- **Recoverable failures:** Temporary provider, generation, or infrastructure failures where another authorized attempt is reasonable.
- **Non-recoverable failures:** Known conditions where repeating normal processing is unsafe or ineffective.
- **Exhausted generation:** No further normal model-processing attempts are permitted; safe response finalization may still be required.
- **Persistence unavailable:** Authoritative state cannot be safely updated; no response or terminal disposition may be assumed committed.

Expected Business outcomes, such as an unavailable slot or rejected Appointment operation, are not infrastructure failures.

#### Exhaustion and finalization

**Exhausting the normal processing budget does not automatically mean the turn has been finalized.**

After exhaustion, the system must still be able to persist an authorized emergency response or establish an explicit terminal disposition.

This finalization must be recoverable if the worker crashes between the last unsuccessful generation attempt and response persistence.

A finalization-only retry must not call OpenAI again or reset the normal processing budget. It must remain discoverable by reconciliation until accepted completion or a durable, actionable unresolved outcome is established.

Use the smallest state/metadata extension compatible with the existing coordinator. Do not introduce a separate workflow engine.

### 2.5. Timeouts and concurrency

Align:

- AI provider request timeout;
- complete Job/worker execution timeout;
- Redis queue reservation expiry (`retry_after`);
- PostgreSQL processing-claim lease;
- retry backoff and reconciliation interval.

The provider call must terminate within the available Job execution budget. The worker timeout must be shorter than Redis reservation expiry.

Claim ownership and stale-attempt fencing remain authoritative even if Redis redelivers a Job or an expired claim is taken over.

Determine concrete timeout values after inspecting the current agent execution limits. Do not introduce claim renewal unless the current implementation demonstrates that it is necessary.

### 2.6. Deterministic emergency responses

When normal AI generation is exhausted, use deterministic customer-facing responses without additional model calls.

**Verified persisted operation result**

Recover the authoritative result belonging to the exact confirming turn.

Accurately distinguish:

- `created`;
- `rescheduled`;
- `cancelled`;
- `unchanged`;
- definitively unsuccessful or rejected results.

Use persisted result details and authorized snapshots for Service, Professional, and Business-local Appointment date/time when available.

Do not infer the original operation outcome from the current Appointment row alone, since the Appointment may have changed afterward.

Never repeat write tools or create another Appointment audit merely to generate the response.

**No verified operation result**

Persist a short, neutral failure response informing the Customer that the request could not be completed and may be retried.

Do not claim that an Appointment was created, modified, or cancelled.

A `prepared` proposal whose model-generated confirmation question was never accepted must remain non-executable. Ensure that terminal handling prevents a later affirmative message from reactivating or executing that proposal.

**Acceptance and diagnostics**

Emergency responses must use the existing ownership-fenced, atomic assistant-message and turn-completion mechanism.

Preserve the original failure cause, attempt history, and fallback usage in durable diagnostics even if the emergency response is successfully accepted.

If PostgreSQL cannot persist the response, do not mark the turn completed or present unsaved text as an accepted response. Keep recovery or controlled investigation possible when persistence becomes available.

No administrator notification is sent in S12A. The stored incident information must remain usable for the future S22 alerting capability.

### 2.7. Ordering and Conversation lifecycle

Preserve existing participant ordering and the S10–S12 Conversation lifecycle.

- A newer inbound message cannot bypass earlier unfinished work without an explicit, safe terminal disposition.
- A message received before acceptance of a proposal confirmation question cannot authorize execution of that proposal.
- A terminal turn without an accepted assistant message may release subsequent work only when its failure disposition is durably recorded and its pending proposal or authorization cannot be reused.
- A replacement Conversation must not inherit outdated proposals, old intent, or abandoned assistant responses.
- Committed Appointment operations survive Conversation replacement, even when their original conversational response can no longer be accepted.

No obsolete response should be inserted into a newer Conversation.

---

## 3. Scope boundaries

S12A does not implement:

- WhatsApp transport, n8n integration, or outbound delivery tracking;
- Professional notifications, Customer reminders, or Calendar synchronization;
- new Appointment mutation, authorization, or audit algorithms;
- a new conversational coordinator or AI agent;
- message batching/debouncing, admission control, or comprehensive AI resource limits;
- an administration panel, administrator alerts, Horizon, or full operational monitoring;
- a generalized outbox or workflow orchestration framework;
- deterministic pre-execution confirmation requests for `prepared` proposals.

S13 owns external messaging. S21 retains broader resilience and resource safeguards. S22 retains comprehensive diagnostics and future administrator alerting.

---

# Task 1 — Durable retry lifecycle

## Goal

Extend the existing AI-turn lifecycle with authoritative retry eligibility, exhaustion, and failure diagnostics.

## Implementation

1. Inspect the current turn schema, claim acquisition, failure handling, completion transaction, and supersession rules.
2. Introduce only the persistent metadata required for:
   - authorized attempt count;
   - next eligible retry time;
   - bounded failure classification and reason;
   - exhausted/finalization-pending disposition;
   - durable terminal outcome and fallback diagnostics.
3. Use additive migrations and existing persistence conventions.
4. Enforce attempt budgets and retry eligibility at the authorized claim boundary.
5. Ensure exhausted turns cannot receive another normal model-processing claim, while interrupted finalization remains recoverable.
6. Define how terminal dispositions interact with participant FIFO and prepared-proposal validity.
7. Preserve the existing explicit processing entry points. Do not introduce Jobs or Scheduler yet.

## Acceptance criteria

- Attempt counts survive restarts and belong to the logical AI turn.
- Only a newly authorized processing claim consumes an attempt.
- Retry timing and exhaustion cannot be bypassed by duplicate or stale requests.
- Failed finalization remains distinguishable from exhausted model-generation attempts.
- Stale claims cannot complete, fail, or modify work owned by a newer claim.
- Later turns advance only after safe accepted completion or durable terminal disposition.
- Existing S10–S12 behavior remains compatible.

## Tests

- First claim, repeated claim, `Busy`, expired claim, stale ownership, and concurrent takeover.
- Retry-time boundaries and attempt exhaustion using a controlled clock.
- Process interruption after an authorized claim.
- Exhaustion followed by interrupted finalization.
- Terminal disposition and subsequent FIFO processing.
- Prepared-proposal, committed-result, and assistant-acceptance regressions.

**Stop after Task 1.**

---

# Task 2 — Asynchronous conversation processing

## Goal

Process newly committed inbound turns through Laravel Queues and Redis without requiring manual invocation.

## Implementation

1. Introduce one thin Laravel Job invoking the existing conversation processor.
2. Connect dispatch to successful inbound message/turn persistence using after-commit semantics.
3. Ensure dispatch failure does not invalidate the already committed inbound data.
4. Load all processing authority and Conversation state from PostgreSQL at execution time.
5. Handle `Busy`, completed, superseded, not-yet-due, and terminal outcomes without unnecessary retries.
6. Continue eligible participant backlog processing through bounded work or continuation Jobs.
7. Provide the minimal development worker configuration consistent with existing infrastructure.

Do not implement reconciliation in this task.

## Acceptance criteria

- A committed inbound turn is queued automatically.
- No Job is dispatched for rolled-back inbound work.
- A worker processes an eligible turn through the existing coordinator.
- Duplicate dispatches do not duplicate accepted assistant messages, Appointments, or audits.
- Processing preserves participant ordering and Customer/Business isolation.
- A failed Redis dispatch leaves persistent work recoverable.
- Eligible backlog does not require another Customer message to continue.
- Direct processing entry points remain available for existing tests and evaluations.

## Tests

- Dispatch after commit and no dispatch after rollback.
- Normal Redis worker consumption.
- Duplicate inbound messages and duplicated queued Jobs.
- Busy claims, concurrent messages, and backlog continuation.
- Redis dispatch failure after successful database commit.
- Previously completed or superseded turns.
- Existing Conversation and Appointment-processing regressions.

**Stop after Task 2.**

---

# Task 3 — Scheduled reconciliation and crash recovery

## Goal

Recover unfinished conversational work automatically from PostgreSQL when normal queued execution is lost or interrupted.

## Implementation

1. Implement one bounded reconciliation command or equivalent small Laravel service.
2. Query authoritative turn state to identify eligible pending, retry-due, expired-claim, and finalization-pending work.
3. Exclude completed, terminal, not-yet-due, actively claimed, and superseded work according to existing lifecycle rules.
4. Re-dispatch through the Task 2 Job without modifying Appointment state or resetting retry budgets.
5. Register execution every minute through Laravel Scheduler.
6. Prevent unnecessary overlapping reconciliation scans using native Laravel facilities when appropriate.
7. Make batch size configurable and document how to run the scheduler locally.

## Acceptance criteria

- A committed turn with a lost or missing Job is eventually processed without another inbound message.
- Expired processing claims are safely recovered.
- Unexpired claims are not stolen.
- Duplicate scans cannot create additional accepted responses or Business mutations.
- Recovery preserves FIFO, participant ownership, and Conversation replacement rules.
- Exhausted turns awaiting finalization remain discoverable, without triggering more normal AI attempts.
- Completed and durably terminal turns are excluded.

## Tests

- Lost dispatch and emptied Redis queue.
- Worker termination followed by claim expiry.
- Eligible and ineligible turn-state selection.
- Overlapping scans and duplicate Jobs.
- Newer inbound messages during recovery.
- Exhausted work awaiting finalization.
- Exactly-once accepted results under concurrent recovery attempts.

Verification must demonstrate that **Scheduler actually initiates recovery**, not merely that the processor can recover when called manually.

**Stop after Task 3.**

---

# Task 4 — Emergency responses and terminal handling

## Goal

Provide safe deterministic responses when normal generation is exhausted, while preserving the original failure and preventing invalid Conversation continuation.

## Implementation

1. Add a minimal deterministic response builder using the exact turn's authorized persisted outcome or a neutral error when none exists.
2. Generate accurate responses for `created`, `rescheduled`, `cancelled`, `unchanged`, and definitive unsuccessful/rejected results.
3. Include available authoritative Appointment details and use the Business timezone.
4. Preserve the existing model-generated confirmation-request requirement for prepared proposals.
5. Prevent abandoned prepared proposals from being executed through later affirmative messages.
6. Persist fallback responses through the existing atomic assistant-acceptance path with current ownership checks.
7. Support safe finalization recovery after a worker crash without additional model-generation attempts.
8. Preserve failure details and fallback usage durably even after successful completion.
9. If persistence cannot complete, retain a recoverable or actionable unresolved outcome rather than fabricating an accepted response.

Do not introduce outbound transport, administrator notifications, or an alternate assistant-message insertion mechanism.

## Acceptance criteria

- Committed create/reschedule/cancel outcomes produce accurate deterministic responses after generation exhaustion.
- `unchanged` and definitive negative outcomes are never misrepresented as successful mutations.
- Exhausted turns without a persisted operation result receive a neutral failure response when persistence is available.
- Prepared proposals cannot be authorized through fallback text or premature confirmations.
- Fallback completion accepts at most one assistant message.
- Fallback never repeats a committed Appointment operation or audit.
- A crashed finalization attempt remains safely recoverable.
- The original processing failure remains diagnosable after successful fallback.
- Persistence failures do not produce false completion.
- Superseded Conversations never receive resurrected responses.

## Tests

- Fallback for persisted create, reschedule, cancel, unchanged, and unsuccessful results.
- Correct response details when current Appointment state differs from the original persisted outcome.
- No-result and prepared-proposal cases.
- Premature affirmative and later-message handling.
- Race between a normal response and fallback completion.
- Crash before or during fallback persistence.
- Cross-Business/Customer isolation and stale ownership.
- Original failure evidence preserved after accepted fallback.
- No duplicate Appointment writes, audits, or assistant messages.

**Stop after Task 4.**

---

# Task 5 — Runtime integration and final verification

## Goal

Verify the complete asynchronous processing and recovery path in the supported development environment.

## Implementation

1. Integrate and verify the minimal Redis worker and Laravel Scheduler startup configuration.
2. Review provider, worker, Redis reservation, claim-lease, and retry timings together.
3. Add or consolidate deterministic end-to-end failure-injection and concurrency tests across the complete workflow.
4. Exercise lost dispatch, worker termination, repeated queue delivery, exhausted generation, and interrupted finalization.
5. Document local worker/scheduler commands, environment configuration, failed-turn inspection, and recovery troubleshooting.
6. Run the complete backend validation and applicable repository checks.
7. Report implementation evidence, unverified operational dependencies, and remaining `[Developer]` actions.

Normal tests must use AI provider fakes. Real Redis/PostgreSQL integration should be verified in a controlled environment; real OpenAI and WhatsApp are not required for S12A acceptance.

## Acceptance criteria

- An inbound message is processed without manually calling the conversation processor.
- A lost Job or interrupted worker is recovered through Scheduler without additional inbound activity.
- Processing ends with one accepted assistant response or an explicit, diagnosable unresolved outcome.
- Retry exhaustion cannot cause unlimited model-processing attempts.
- Prepared proposals, committed operations, audits, ordering, ownership, claim fencing, and Conversation lifecycle retain their existing guarantees.
- Deterministic fallback uses the correct persisted result and preserves incident evidence.
- Worker and scheduler configuration has been exercised in the supported environment.
- Required automated checks pass, and unavailable checks are explicitly reported.

## Verification

Run and report:

- All new S12A automated tests.
- Relevant S10–S12 regression suites.
- PostgreSQL transaction and concurrency tests.
- Queue consumption and scheduled-reconciliation integration checks.
- Failure injection at dispatch, provider execution, proposal persistence, committed Appointment execution, assistant persistence, and finalization.
- Complete backend test suite.
- Mandatory formatting, linting, and other applicable repository checks.

Do not weaken established tests to satisfy S12A.

### Developer actions

- **[Developer]** Review and approve local worker/scheduler startup configuration and required environment-specific settings.
- **[Developer]** Verify or review a local demonstration with Redis, worker, and Scheduler running, including an interrupted-work recovery scenario.
- **[Developer]** Review the final implementation, accept S12A, and update its status in `v1.md`.
- **[Developer]** Control all Git staging, commits, and repository-history changes.

**The coding agent must not create Git commits.**

---

## 4. Slice acceptance

S12A is complete when:

1. Durable inbound conversational work automatically enters asynchronous processing.
2. Eligible interrupted or abandoned work is recovered from PostgreSQL without another Customer message.
3. Retry attempts are bounded durably, including interrupted finalization handling.
4. Existing S10–S12 correctness guarantees remain intact.
5. Committed Appointment operations and audits are never duplicated during recovery.
6. Exhausted generation yields an appropriate persisted deterministic response when safe and possible.
7. Unresolved failures remain explicitly diagnosable without fabricated success.
8. Worker/Scheduler operation and relevant failure scenarios have been verified.
9. Automated validation passes and the Developer accepts the implementation.

## 5. Implementation protocol

Follow repository `AGENTS.md` and applicable nested instructions.

Implement **only the task explicitly requested by the Developer**, in sequence. After each task:

- Stop before starting the next task.
- Report files changed, tests added, commands executed, and results.
- Identify any remaining `[Developer]` actions or unverified behavior.
- Report a genuine conflict with existing implementation instead of silently changing an approved design.

Run the complete automated validation relevant to the backend after each requested task, as required by repository instructions.

Do not implement future slices, add speculative infrastructure, or modify Git state.

The Developer owns task approval, commits, and the final roadmap status update.
