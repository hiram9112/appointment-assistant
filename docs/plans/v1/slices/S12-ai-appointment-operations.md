# S12 — AI Appointment operations

**Status:** Done  
**Depends on:** S04, S05, S06, S07, S08, S10, S11  
**Covers:** FR-04; FR-05; FR-06; FR-07; FR-08; FR-14; BR-07; BR-10; BR-13

## Goal

Extend the existing Laravel AI SDK conversational agent to register Customers, view their Business-scoped Appointments, and create, reschedule, or cancel Appointments through existing authoritative Laravel capabilities. Each Appointment-changing operation requires explicit natural-language confirmation of a persisted, customer-visible proposal before execution.

The AI model generates customer-facing conversational responses, including confirmation requests, from authoritative structured application data. Laravel retains control of identity, Business rules, proposal persistence, authorization, and execution.

Preserve S10 Conversation lifecycle and S11 turn ordering, recovery, and stale-attempt protection. Do not move scheduling rules or ownership decisions into the model.

---

## Design decisions

### Trusted identity and application boundaries

- Reuse `ConversationTurnProcessor`, `ConversationAgent`, `AiTurnCoordinator`, `AiTurnClaim`, S10 persistence, and S04–S08 application capabilities. Do not introduce a second agent, turn coordinator, or Conversation store.

- Business, channel, sender identity, Conversation, Customer identity, and turn claim come from trusted Laravel context, never AI tool arguments. Resolve Customers using S04's trusted channel identity; names and optional phone are registration data, not identity proof.

- S04 currently supports `whatsapp` identities. S12 does not introduce transport, identity verification, or additional channel support; S13 owns the real WhatsApp integration.

- Register an unknown Customer using `RegisterCustomer`, then associate the resolved Customer with the authorized current Conversation. Continue the existing booking flow with established history, including within the same AI turn. Re-resolve identity when needed after registration rather than relying on a Customer snapshot captured before the call.

- Registration and read operations do not require the additional Appointment confirmation exchange. Registration is still an authorized, recoverable write; unique channel identity and `ResolveCustomer` handle retry after successful persistence.

- `GetCustomerAppointments` must filter **both** the trusted Customer and the current Business in PostgreSQL. Preserve S06's history, statuses, ordering, DTO, and Business-local timezone semantics. Update the capability contract and its callers as necessary; do not filter only after records reach the model.

- The agent may use application-returned Appointment IDs internally, but must clarify ambiguous references and must not disclose or modify another Customer's or Business's records.

### Durable proposal lifecycle

Use one minimal persistent proposal entity for `create`, `reschedule`, and `cancel`. Persist the Conversation, originating AI turn, operation type, immutable canonical payload, canonical presentation details, state, accepted assistant confirmation-request message (when present), confirming turn (when present), durable execution outcome (when present), and timestamps. Avoid a generic workflow engine or unrelated state fields.

| State       | Meaning                                                                                                                                                   |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `prepared`  | Proposal saved; no assistant confirmation request has yet been accepted and persisted by S11.                                                             |
| `pending`   | A model-generated assistant confirmation request has been accepted, persisted, and associated with the proposal; awaiting a subsequent Customer response. |
| `completed` | The authoritative Appointment operation completed and its result was persisted.                                                                           |
| `rejected`  | Proposal declined, replaced, invalidated by Conversation replacement, or definitively non-executable.                                                     |

- **Exactly one active proposal (`prepared` or `pending`) per Conversation**, enforced with a PostgreSQL partial unique index. Historical terminal proposals remain available.

- Every new proposal atomically transitions the existing active proposal to `rejected` **and** inserts its own `prepared` record. A proposal's operation type and canonical parameters never change. Replacing an unpresented `prepared` proposal also invalidates it.

- Idempotent retry of the same originating AI turn reuses its existing proposal; if a retry tries to prepare different parameters for that same turn, it must not silently replace them. Constrain the originating-turn association accordingly. A replacement initiated by a _new_ turn is a new proposal.

- The old proposal stops being confirmable immediately upon replacement. If the new assistant generation fails, its `prepared` proposal is recoverable; the old one does not return to `pending`.

- Promote `prepared → pending` **in the same transaction** that S11 accepts and persists the model-generated assistant confirmation request and completes its originating turn. The accepted assistant-message reference must identify the actual persisted response of that originating turn. A failed or stale completion cannot promote a proposal. A `prepared` proposal cannot be executed.

- A `pending` state proves that the assistant response was accepted and persisted, not that Laravel semantically verified the model-generated wording. Conversational accuracy is evaluated through agent instructions, tests, and Developer live verification.

- New Conversations do not inherit old proposals or history. S10's configured inactivity threshold (initially 12 hours) remains the only Conversation timeout; no separate timer or cleanup job. Nonterminal proposals of superseded Conversations are ineligible for execution and can be marked `rejected` through a guarded invalidation path. Completed results remain intact.

- An inbound confirmation accepted into an existing Conversation remains valid for processing even if 12 hours elapse while AI is running, unless a newer Conversation actually supersedes it; do not add an elapsed-time check during provider execution.

### Presentation and confirmation

- First establish the exact target Appointment, Service, selected Professional, date/time, and other required data. A new creation requires Customer selection of an eligible Professional; **rescheduling retains the current Professional** unless the Customer requests a change, consistent with S07. Never silently substitute a Professional.

- Check candidate creation availability through the existing scheduling capabilities. A rescheduling candidate must use S07's own-Appointment occupancy exclusion; do not treat the original Appointment as a competing booking. Availability checks for presentation are informational and do not hold the slot.

- Proposal-preparation tools return structured information derived from the **persisted canonical proposal**, not a pre-rendered confirmation question. Include the operation, Service, Professional, Business-local date/time information, and original/proposed Appointment details where applicable. Preserve exact instants, offsets, and timezone information in the structured data.

- **The AI model is responsible for composing the customer-facing confirmation request.** It uses the authoritative structured tool response to describe the proposed operation naturally. Laravel must not replace, reconstruct, concatenate, or rewrite the model-generated confirmation response using deterministic templates.

- V1 customer-facing responses should use natural, concise, professional Spanish. Confirmation questions should identify the proposed operation and relevant Service, Professional, and date/time. Rescheduling must clearly identify the original and proposed details, including a Professional change where applicable. The model should express dates naturally rather than unnecessarily exposing ISO 8601 timestamps, UTC offsets, or technical timezone identifiers.

- The model may adapt the wording to the conversational context, including answering informational questions while requesting confirmation in the same response. It must not claim a prepared operation has already been executed.

- Model-generated wording is not an authoritative source of Appointment parameters. The application executes only the immutable persisted proposal. The residual possibility of inaccurate model wording is accepted as a property of the AI-assisted system and addressed through appropriate instructions, automated tests, adversarial scenarios, and live-model verification. Do not introduce a secondary semantic validator, another model call, a placeholder-injection mechanism, or a deterministic confirmation renderer.

- Explicit natural-language confirmation is mandatory for creation, rescheduling, and cancellation; no operation code or button is required. The model interprets acceptance, refusal, and ambiguity, but Laravel validates the exact stored proposal and authoritative rules.

- Confirmation must be a **subsequent persisted inbound Customer message**, belonging to the same active Conversation, with a message ID later than the accepted assistant confirmation-request message ID. A preemptive "yes" received before the question was accepted cannot authorize the operation, even if its turn is processed afterward.

- On affirmative confirmation, the model invokes the confirmation operation without supplying fresh Service, Professional, start time, Appointment, Customer, or Business parameters. Conditional acceptance (for example, "yes, but at 11"), ambiguity, or a changed preference must not execute the previous proposal. A changed selection is prepared as a new proposal and requires its own later confirmation.

- If the Customer declines, reject the proposal without changing an Appointment. On a definitive business conflict at execution (for example, the slot was taken), reject it, explain the result, and discuss alternatives; each accepted alternative is a new proposal with a new confirmation.

- Confirmations guarantee a request was **accepted and stored by S11**, not delivered by an external messaging provider. Delivery belongs to S13.

### Authorized writes, atomicity, and recovery

- Extend the existing S11 ownership mechanism narrowly so tool-triggered writes execute under a current `AiTurnClaim`, participant-scoped synchronization, and current-Conversation check. A stale claim must not initiate a write. Preserve the established lock order: participant advisory lock, turn validation, proposal row, then existing Appointment locks when applicable.

- Keep OpenAI calls outside transactions and locks. Execute proposal-state checks, authoritative S05/S07/S08 capability calls, Appointment audit, and durable operation-result persistence within one coherent short PostgreSQL transaction. For registration, protect the current Conversation association and use S04's existing uniqueness guarantee.

- The authoritative capability revalidates Customer ownership, eligibility, appointment status, date/time, and availability at execution. S05/S07 already handle occupancy/exclusion conflicts; S08 controls cancellation. Supply `actorType`, `actorId`, and `source` from trusted Laravel context, never model input. Do not duplicate scheduling or audit logic in tools.

- Existing capabilities open `DB::transaction()` themselves. Verify actual nested-savepoint behavior, `attempts: 3`, exception handling, PostgreSQL `23P01`, and rollback under the outer S12 transaction before relying on atomicity or automatic retry.

- If generation fails after proposal preparation but before assistant-message acceptance, recover the existing immutable `prepared` proposal and make its structured details available to the model on retry. The model may generate new wording; do not regenerate the proposal, rerun availability checks, or reuse an unpersisted assistant response as authoritative. Keep provider execution outside transactions.

- Persist success and the corresponding proposal `completed` result atomically, including the confirming turn association. Recover a completed result by **proposal/confirming turn** after an AI or response-persistence failure; do not rerun the Appointment capability, recheck occupied availability as if the prior success failed, or depend on replayed SDK tool traces.

- Make recovered authoritative results available as structured context for the model to communicate naturally. A completed Appointment result must not be reconstructed from the model's prior prose.

- Handle repeated confirmations, repeated tool calls, and overlapping provider attempts without duplicate writes or audits. A completed proposal is terminal. A separate later "yes" cannot execute the same operation again.

- Distinguish definitive application outcomes from infrastructure errors. A definitive unavailable/not-eligible outcome may make a proposal `rejected`; database/provider errors must roll back without fabricating rejection or success. Preserve recovery of the authorized turn.

- S07 may return an unchanged Appointment for a no-op rescheduling. Report accurately that no change was made, and do not claim a new rescheduling audit event.

- Report an Appointment-changing success only after authoritative persistence, using the stored application result. Existing external-side-effect guarantees remain owned by the relevant application capability; S12 does not implement synchronization or notifications.

---

## Implementation tasks

### Task 1 — Authorized execution and Business-scoped reads

**Goal:** Establish a reusable, narrowly scoped transactional authorization boundary for S12 writes and isolate Appointment reads by Business.

**Implementation**

1. Extract or expose the required S11 ownership check without weakening `complete()`, `fail()`, turn leases, or stale-attempt handling. Make authorized write callbacks execute under the existing participant lock and database transaction, not during an OpenAI transaction.

2. Verify the claimed turn is `processing`, the token matches, and its Conversation is still the participant's current Conversation. Preserve participant isolation and lock order.

3. Update `GetCustomerAppointments` to require trusted Business and Customer scope; preserve S06 output semantics and update existing callers.

**Acceptance and tests**

- Valid claims authorize a bounded callback; stale, completed, superseded, and mismatched claims cannot cause any write.
- Callback failure rolls back, without accepting a stale AI response or changing S11's response-completion guarantee.
- PostgreSQL concurrency tests cover claim takeover, Conversation replacement, same-participant serialization, and different-participant progress.
- Appointment read tests cover the same Customer with Appointments in two Businesses, another Customer, history/status ordering, and timezone conversion.
- Existing S06, S10, and S11 regressions pass.

**Stop after Task 1; do not implement later tasks.**

### Task 2 — Customer registration and Appointment read tools

**Goal:** Expose S04 and S06 capabilities to the agent without transferring identity or ownership decisions to the model.

**Implementation**

1. Resolve the Customer through `ResolveCustomer` using trusted `channel` and `senderIdentity`; support renewed resolution after registration within the same turn.

2. Implement a registration tool that accepts first name, surname, and optional phone but injects trusted channel identity. Delegate to `RegisterCustomer` and associate the resulting/resolved Customer with the current Conversation using the authorized-write boundary.

3. Treat `AlreadyRegistered`, duplicate tool calls, concurrent registration, and an interrupted post-commit response by resolving the existing Customer; do not create duplicates or restart the Conversation.

4. Implement a Business-scoped `GetCustomerAppointments` tool. Return clear structured outcomes and deny unidentified or out-of-scope reads.

**Acceptance and tests**

- A new supported identity registers once; the existing identity is reused on retry and across Conversations. Phone refusal is permitted; names and phones never establish identity.
- Registration and Conversation association are transactionally consistent and cannot be performed by stale attempts. Existing context supports the booking flow after registration in the same turn.
- Only Appointments of the identified Customer and current Business are returned; zero/multiple-result and failure cases are distinct.
- Tests cover validation, supported-channel restrictions, duplicate/concurrent registration, rollback, identity spoofing, post-commit recovery, and read isolation.

**Stop after Task 2.**

### Task 3 — Durable proposal model and transitions

**Goal:** Persist a minimal four-state proposal lifecycle with transactional replacement and idempotent preparation.

**Implementation**

1. Create the proposal migration, model, and application capability; use canonical typed operation payloads, immutable parameters, and references to the originating turn and accepted assistant confirmation-request message.

2. Add PostgreSQL constraints/indexes for one active (`prepared` or `pending`) proposal per Conversation and one stable proposal per originating turn. Ensure Conversation/turn association and Business scope are validated.

3. Implement atomic replacement (old active → `rejected`, new → `prepared`), lookup/recovery of the same turn's prepared proposal, guarded transitions, and rejection of proposals in replaced Conversations.

4. Preserve terminal records and durable result fields; do not add `superseded`, `cancelled`, or `processing` proposal states.

**Acceptance and tests**

- Concurrent inserts cannot create two active proposals; replacement does not leave two active rows at commit or expose the earlier proposal for confirmation afterward.
- Retries reuse the same originating proposal and reject mismatched new parameters; immutable payloads and terminal states cannot be rewritten.
- Failure during replacement rolls back both the old-state transition and new insert; failure afterward leaves the new `prepared` proposal recoverable.
- Tests cover PostgreSQL uniqueness, atomicity, invalid transitions, cross-Conversation references, expired Conversation behavior, and retention of completed/rejected records.

**Stop after Task 3.**

### Task 4 — Prepare operations and persist confirmation requests

**Goal:** Prepare canonical Appointment actions, expose authoritative structured proposal details to the AI model, and atomically persist the model-generated confirmation request without changing an Appointment.

**Implementation**

1. Implement validated preparation tools for create, reschedule, and cancel using trusted scope, resolved Appointment references, eligibility, and availability. Reuse the S07 `CalculateAvailability::forRescheduling` path (or its existing equivalent) for self-exclusion rather than the general read-only availability tool. Preserve explicit Professional selection.

2. Return a consistent, structured, model-readable result derived from the persisted canonical proposal. Include the operation, relevant Service and Professional names, exact proposed start/end instants, Business timezone, and original Appointment details where applicable. For rescheduling, include original and proposed Professional details, including changes. Do not return a backend-rendered `confirmation_request` string. Repeated calls for the same originating turn must return the persisted immutable details without modifying the proposal.

3. Preserve the model-generated assistant response rather than replacing its content. Extend S11 turn completion minimally so persisting that assistant message, completing the originating turn, associating the accepted assistant-message ID with the proposal, and promoting `prepared → pending` occur in the **same transaction** under current claim ownership. Preserve existing response validation and failure handling. The accepted message reference must match the actual persisted response of the originating turn.

4. Recover `prepared` proposals when the originating turn is retried. Before model generation, detect the existing proposal and supply its persisted structured details as authoritative context for composing a new confirmation request. Do not generate a backend confirmation message, repeat availability checks, recreate the proposal, or replace its canonical parameters. A stale generator cannot promote the proposal or create a replacement after losing ownership.

5. Keep OpenAI generation outside transactions and locks. Adapt the existing generation/context interface only as needed to support prepared-proposal recovery. Full agent tool registration and general multi-turn integration remain Task 6.

**Acceptance and tests**

- Preparation performs no Appointment mutation; missing/ambiguous references ask for clarification.
- Create requires explicit Professional selection; reschedule defaults to the existing Professional unless a change is requested.
- Preparation tools return structured authoritative data for create, reschedule, and cancel, including original/new details for rescheduling. Tool arguments cannot override trusted identity or Business scope.
- The model-generated assistant message is persisted without backend rewriting, template substitution, or loss of unrelated conversational content.
- Assistant-message persistence, originating-turn completion, confirmation-request association, and `prepared → pending` are atomic. Failed assistant insertion, failed completion, or a stale claim cannot leave a falsely `pending` proposal.
- Recovery reuses the same prepared proposal and supplies its persisted structured details to the model for another generation attempt. No duplicate proposal, availability recalculation, or Appointment mutation occurs during recovery.
- A retry may generate different wording before acceptance. Once the assistant message is accepted, the actual persisted message remains associated with the proposal.
- Tests cover exact timestamps, DST, alternative Professionals, S07 self-exclusion, structured output, preservation of model-generated content, provider interruption, repeated preparation, rollback, and PostgreSQL concurrency.
- SDK fakes establish structural correctness and response preservation, not real-model semantic accuracy. No deterministic backend wording assertions or secondary semantic validator are required.

**Stop after Task 4.**

### Task 5 — Confirmed execution and idempotent result recovery

**Goal:** Execute an accepted, confirmed proposal exactly once at the authoritative application boundary and preserve recoverable results.

**Implementation**

1. Locate the active `pending` proposal from trusted Conversation context. Verify current claim and Conversation, the accepted assistant confirmation-request reference, and inbound-message ID ordering (`confirmation inbound ID > confirmation request message ID`).

2. Handle explicit decline without Appointment mutation. For affirmative confirmation, execute only the persisted proposal parameters; do not accept new operation parameters through the tool. The model interprets the Customer's response; Laravel validates the current proposal and execution authorization rather than reconstructing parameters from conversational text.

3. Under participant/turn/proposal locking and a coherent transaction, invoke S05/S07/S08 as appropriate, persist the authoritative outcome and confirming-turn reference, and transition to `completed` or definitive `rejected`.

4. Make repeated tool calls, repeated confirmation messages, and reattempts of the confirming turn read the durable result. Surface that authoritative result to `ConversationTurnProcessor` on retry **before** model generation or renewed tool execution. Make the stored result available as structured context for natural model-generated communication. Do not rerun a committed Appointment capability or depend on replayed SDK tool traces.

5. Verify nested Laravel transactions, savepoints, deadlock/serialization behavior, exclusion conflicts, and exception boundaries against the actual existing capabilities.

**Acceptance and tests**

- No mutation without a `pending` proposal associated with an accepted, persisted assistant confirmation-request message and a **later persisted** affirmative Customer message in the same current Conversation. A "yes" sent before the request was accepted cannot confirm it.
- Model-generated wording does not authorize new operation parameters. The execution tool must use the stored proposal exclusively.
- Claim loss, Conversation replacement, wrong Customer/Business, duplicate confirmations, and concurrent attempts cannot cause unauthorized or duplicate writes/audits.
- A slot taken between proposal and execution produces a definitive failure, rejects that proposal, and leaves the Appointment system consistent; a new alternative needs fresh confirmation.
- Rescheduling preserves the old slot on failure; cancellation obeys current eligibility and late-notice audits; no-op reschedule is reported without inventing a change.
- A successful Appointment commit followed by provider or assistant-message failure is recovered as success with no second domain mutation. The recovered structured result must accurately identify the prior outcome. Technical errors roll back and remain retryable.
- Use real PostgreSQL for concurrency, `23P01`, savepoint, rollback, and recovery tests.

**Stop after Task 5.**

### Task 6 — Agent integration and multi-turn behavior

**Goal:** Integrate the new tools, Customer state, durable proposals, and recorded outcomes into the single existing agent, with model-generated conversational responses grounded in authoritative application data.

**Implementation**

1. Extend `ConversationAgent` tool registration and instructions, retaining S11 runtime Business context, external completed-turn history, and `CheckAvailabilityTool`.

2. Inject trusted execution context and fresh Customer/proposal state. Handle registration-to-booking continuity without requiring repeated information or a new Conversation. Ensure the model can consume structured preparation details and authoritative recovered results.

3. Guide natural-language intent interpretation: explicit approval, decline, clarification, changed parameters, Appointment selection, eligibility conflicts, and failures. Never let the model bypass backend authorization. A conditional or ambiguous response must not execute the stored proposal.

4. On retries, provide a committed result from the current confirming turn as authoritative context; avoid repeated execution. Treat a new Conversation as a fresh interaction while retaining persistent Customer/Appointments. Preserve the prepared-proposal recovery behavior established in Task 4.

5. Configure the model to generate natural, concise, professional Spanish customer-facing messages, including confirmation requests. The model should accurately present operation type, Service, Professional, and relevant dates/times from authoritative structured tool results. For rescheduling, explain original and proposed details, including Professional changes when applicable. Do not introduce backend-generated confirmation templates.

6. Distinguish proposal preparation from completed execution. Never announce that an Appointment has been created, rescheduled, or cancelled until authoritative persistence confirms success. Report conflicts, rejections, and no-op outcomes according to stored application results.

7. Preserve conversational continuity for mixed-intent messages. When a Customer requests information and an Appointment operation in the same turn, the model should answer the informational question and request confirmation in one coherent response where appropriate. No backend response override should discard useful model-generated content.

8. Keep all customer-facing responses in the existing single-agent architecture. No SDK-native Conversation persistence or new infrastructure.

**Acceptance and tests**

- Complete create/view/reschedule/cancel flows work for registered Customers; unregistered Contacts can register and continue booking in the same Conversation.
- Create, reschedule, and cancel require separate explicit natural-language confirmation; negative, conditional, ambiguous, and premature replies never execute the previous proposal.
- Changed selections atomically replace the old proposal; only the latest active proposal can be confirmed.
- The model receives authoritative structured proposal details and generates confirmation requests without backend rewriting. The accepted response is durably associated with the correct proposal.
- Mixed-intent responses preserve the informational answer and confirmation question instead of discarding model-generated content.
- Model instructions distinguish preparation from successful execution and require natural Spanish language, including customer-friendly date expressions.
- Availability, ownership, current Business configuration, and durable operation results determine answers; previous S11 read enquiries still work.
- SDK-fake tests cover tool wiring, fresh identity, history, expiration, prepared-proposal recovery, outcome recovery, response preservation, and all branching cases.
- SDK fakes verify orchestration, persistence, and exposed context; they do not establish live-model semantic reliability.

**Stop after Task 6.**

### Task 7 — Full verification and Developer acceptance

**Goal:** Validate the integrated behavior, model-generated confirmation accuracy, and regressions before marking S12 complete.

**Automated verification**

1. Run the complete backend test suite and repository-required formatting, lint, and static-analysis checks.

2. Run targeted S04–S08, S10–S11 regression tests, plus real-PostgreSQL integration and concurrency tests from Tasks 1–6.

3. Verify that preparation tools expose persisted structured data, model-generated assistant responses are not overwritten, and confirmation-message association remains atomic. Verify prepared-proposal recovery does not recreate or change its payload.

4. Cover mixed-intent response preservation, prepared versus completed result handling, stale attempts, rollback, idempotency, and subsequent inbound-message ordering.

5. Report any test environment limitations and unresolved implementation risks; do not weaken tests to pass. Automated SDK fakes cannot prove real-model language accuracy.

**Live OpenAI verification — [Developer]**

Validate bounded real conversations for the following scenarios:

- Unregistered Customer registration followed by booking, including optional-phone refusal and continued use of the same Conversation.
- Registered Customer Appointment listing, creation, rescheduling, and cancellation.
- Mandatory explicit confirmation for all three Appointment-changing operations.
- Natural Spanish confirmation requests that identify the correct operation, Service, Professional, and relevant dates/times.
- Rescheduling while retaining the current Professional and rescheduling with an explicitly requested Professional change.
- Natural date references including "mañana", weekdays, month boundaries, and timezone/DST edge cases. Verify that the resulting instants match the persisted proposal.
- Natural affirmative, negative, conditional, ambiguous, and premature replies.
- Changes to a proposed Professional, date, or time, requiring a new proposal and confirmation.
- A slot becoming occupied between preparation and confirmation.
- Multiple existing Appointments, including ambiguous references that require clarification.
- Mixed-intent interactions, such as asking for a Service price while requesting a booking. Verify that the model answers the information request and presents the proposed operation coherently.
- Interrupted generation after proposal preparation. Verify that the same immutable proposal is reused and that the model receives sufficient structured information to generate a confirmation request.
- Completed-operation recovery after provider or assistant-response persistence failure. Verify that no second Appointment operation occurs and the response reports the persisted outcome correctly.
- A new Conversation after inactivity, without inheriting previous proposal state.
- S11 descriptive enquiries, Business information, and availability regressions.

For each Appointment-changing scenario, compare the model-presented action, Service, Professional, and relevant date/time against the actual persisted proposal. Examine whether the model incorrectly claims completion before execution or misreports a definitive failure or no-op operation.

Inspect authoritative Customer, Conversation, proposal, Appointment, and audit records. Provider responses alone are not evidence of successful persistence or execution.

Treat material semantic discrepancies as defects to investigate and correct before Developer acceptance. Use normal agent instructions, tool contracts, and focused tests to address failures. Do not introduce deterministic confirmation renderers or secondary semantic-validation infrastructure solely to eliminate the residual possibility of model wording errors.

**Completion criteria**

- All S12 acceptance criteria and relevant prior-slice regressions pass.
- The model generates customer-facing confirmation wording from authoritative structured proposal details without backend replacement.
- Accepted assistant confirmation messages are durably associated with the corresponding proposals; subsequent Customer confirmation and authoritative execution remain protected.
- The Developer reviews live-model confirmation accuracy and verifies critical details against PostgreSQL records.
- The Developer reviews the implementation, executes the marked live scenarios, and accepts the implementation.
- Update `v1.md` only after acceptance. The Developer controls Git operations.

---

## Scope boundaries

S12 excludes WhatsApp/n8n transport (S13), web/demo endpoints, Business Admin UI, calendar sync, notifications and reminders, new authentication methods, automatic Professional assignment, fixed slot grids, persistent AI semantic memory, new queues/schedulers, and generic workflow infrastructure. Existing Laravel capabilities and PostgreSQL remain authoritative.

S12 does not introduce a separate confirmation-message template engine, an additional LLM validation stage, or a semantic verification service. Model-generated conversational wording is accepted within the existing agent architecture, while authoritative domain writes remain fully controlled by Laravel.

## Implementation references

- `docs/product.md`: covered FR/BR sections listed above.
- `docs/architecture.md`: Laravel AI, identity/security, PostgreSQL and integration boundaries.
- `docs/adr/0001-pragmatic-modular-monolith.md`
- `docs/adr/0002-authoritative-state-postgresql.md`
- `docs/adr/0004-laravel-ai-sdk.md`
- Existing accepted S04–S08, S10, and S11 slice plans under `docs/plans/v1/slices/`.
- Repository `AGENTS.md` and backend-specific committed rules for each implementation task.

---

### Task 7 verification record — 2026-11-20

**Verification status:** Completed. Final Developer acceptance and roadmap update remain separate actions.

#### Automated verification

The corrective implementation pass completed the following automated checks:

| Verification                                                     | Result                          |
| ---------------------------------------------------------------- | ------------------------------- |
| Complete backend suite (`composer test`)                         | 700 tests passed                |
| Focused Conversation Agent and evaluation tests                  | 166 passed                      |
| Rescheduling and occupancy integration tests                     | 39 passed                       |
| Laravel Pint                                                     | Passed                          |
| Composer lint                                                    | Passed                          |
| Changed PHP syntax checks                                        | Passed                          |
| PostgreSQL integration, transaction, and concurrency regressions | Passed within the backend suite |

The focused test counts are separate verification runs and must not be added to the full-suite total.

The S11 evaluation harness initially exceeded its cumulative request-byte budget after the agent instruction changes. The instructions were shortened, and the affected tests subsequently passed without increasing request limits or relaxing assertions. No separate static-analysis tool was configured.

These automated results were reported by the coding agent. Live-model evaluation and Developer review were performed separately.

#### Live-model evaluation

**Evaluation date:** 2026-11-20  
**Provider:** OpenAI  
**Model:** `gpt-6-luna`  
**Environment:** Local evaluation environment with isolated, seeded PostgreSQL fixtures  
**Business timezone:** `Europe/Madrid`  
**Evaluation suite:** `appointment-operations`  
**Scenarios reviewed:** 21

The evaluation runner exercised bounded, real-model conversations using synthetic Business, Customer, Appointment, and Conversation data. Scenario-specific operation times were fixed to make relative-date and daylight-saving-time behavior reproducible.

Evidence included model responses, executed tool names and arguments, structured tool results, contract checks, Conversation history, runtime context, and before/after PostgreSQL snapshots.

Each scenario was manually reviewed against both its expected conversational behavior and authoritative persisted state. Automated contract success alone was not treated as sufficient evidence of correctness.

The following scenarios were examined:

| Coverage area                          | Scenarios                                                                                   |
| -------------------------------------- | ------------------------------------------------------------------------------------------- |
| Core conversational workflows          | `mixed-intent`, `registered-lifecycle`, `registration-continuity`, `information-regression` |
| Confirmation and proposal changes      | `negative-reply`, `ambiguous-reply`, `changed-selections`, `premature-reply`                |
| Availability and Appointment selection | `occupied-slot`, `ambiguous-appointments`                                                   |
| Prepared-proposal recovery             | `prepared-provider-recovery`, `prepared-assistant-recovery`                                 |
| Completed-operation recovery           | `completed-provider-recovery`, `completed-assistant-recovery`                               |
| Conversation isolation                 | `new-conversation`                                                                          |
| Natural dates and timezone behavior    | `tomorrow`, `weekday`, `month-boundary`, `spring-dst`, `autumn-dst`                         |
| No-change requests                     | `unchanged-reschedule`                                                                      |

The initial manual review produced:

| Classification                                  | Count |
| ----------------------------------------------- | ----: |
| Accepted scenarios                              |    19 |
| Functional defect requiring correction          |     1 |
| Evaluation-design mismatch requiring correction |     1 |
| Total scenarios reviewed                        |    21 |

The `completed-provider-recovery` scenario also revealed a non-blocking confirmation-wording issue.

#### Findings and corrective actions

**1. Completed-operation conversational continuity**

Scenario: `completed-assistant-recovery`

The original execution successfully created an Appointment and recovered its durable result after an injected assistant-response persistence failure. However, a subsequent separate Customer message, `Sí, otra vez.`, caused the agent to incorrectly state that it could not verify the previously confirmed booking.

The underlying recovery, persistence, and idempotency mechanisms were functioning correctly. The defect concerned how the agent interpreted a previously communicated successful result on a later conversational turn.

The agent instructions were updated to distinguish authoritative current-turn execution results from successful outcomes already established in completed Conversation history. A repeated affirmative must not be interpreted as authorization for another operation.

The corrected live evaluation confirmed that the agent acknowledges the previous booking and requests clarification before considering another reservation.

**2. Confirmation wording**

Scenario: `completed-provider-recovery`

The initial evaluation produced a confirmation question equivalent to `¿Confirmas que prepare esta reserva?`, although the proposal had already been prepared.

The agent and confirmation-tool instructions were clarified to distinguish proposal preparation, Customer authorization to execute, and completed execution.

The corrected evaluation generated:

> He preparado la reserva de **Deep facial cleansing** con **Laura Martín** para hoy, **9 de noviembre, de 15:30 a 16:30**. ¿Confirmas esta reserva?

The model remains responsible for generating customer-facing language. No deterministic confirmation renderer, response rewriting, or secondary semantic-validation mechanism was introduced.

**3. Unchanged rescheduling evaluation**

Scenario: `unchanged-reschedule`

The original Customer request explicitly instructed the agent to keep an existing Appointment unchanged. The agent correctly verified the Appointment and made no modifications, but the evaluation contract incorrectly required preparation and confirmation of a rescheduling operation.

The live evaluation contract was corrected to expect a Business- and Customer-scoped Appointment read with no write operations.

The technical `unchanged` outcome remains covered separately by deterministic PostgreSQL tests, including same-slot preparation, confirmation boundaries, self-occupancy exclusion, ownership checks, unchanged persistence, and absence of unnecessary audits.

This correction preserved both conversational correctness and the underlying domain-level coverage.

#### Corrective regression verification

After implementing the corrections, three affected scenarios were rerun against the live OpenAI model.

| Scenario                       | Run ID                                 | Manual result |
| ------------------------------ | -------------------------------------- | ------------- |
| `completed-assistant-recovery` | `0d626725-4a7a-45e7-8f3d-67a7bcdc0a11` | PASS          |
| `completed-provider-recovery`  | `0a4147ff-286a-49bc-938c-0db49e3de403` | PASS          |
| `unchanged-reschedule`         | `5e423c9d-55a9-4a70-8e52-193a0f2814fa` | PASS          |

**Completed assistant-response recovery**

The evaluation verified that:

- An accepted confirmation executes the canonical persisted proposal.
- The Appointment and audit are committed exactly once.
- An injected interruption leaves the committed operation recoverable.
- Retrying the confirming turn reports the persisted successful result without repeating Appointment tools.
- A later ambiguous affirmative does not reactivate the proposal, create another Appointment, or contradict the known confirmation.

The scenario completed all four turn contracts successfully. PostgreSQL contained one new confirmed Appointment (`#9`), one corresponding `created` audit, and a terminal `completed` proposal.

**Completed provider/execution recovery**

The evaluation verified the improved confirmation wording, successful recovery following an injected `after_execution` interruption, and correct handling of a subsequent repeated affirmative.

The scenario completed all four turn contracts successfully. Recovery performed no duplicate execution, and the final persisted state contained one new Appointment, one audit, and no reactivated proposal.

**Unchanged rescheduling**

The corrected evaluation executed `GetCustomerAppointmentsTool` and verified that the existing confirmed Appointment remained unchanged.

The agent made no preparation or confirmation calls, created no proposal, modified no Appointment, and generated no audit. The scenario passed its updated contract.

#### Acceptance assessment

The combined initial review and targeted corrective regressions support the following outcome:

- **21 of 21 scenarios have an accepted manual review outcome** after correction.
- The identified conversational defect was corrected and successfully reevaluated.
- The confirmation-wording observation was addressed and successfully reevaluated.
- The unchanged-rescheduling evaluation contract was aligned with Customer intent, while deterministic backend coverage retained the technical `unchanged` guarantee.
- No duplicate Appointment creation, unexpected audit, or proposal reactivation was observed in the corrective recovery evaluations.
- Previously accepted scenarios were not all rerun after the corrections; the post-correction live verification targeted the three affected scenarios, supplemented by the complete automated backend regression suite.

The evidence supports acceptance of the implemented S12 behavior within the evaluated scope. It does not establish statistical reliability across every possible model response, nor does it verify external WhatsApp message delivery, which remains outside S12.

The full generated JSON evidence is retained in the local private evaluation storage under:

`backend/storage/app/private/appointment-operations-evaluation/`

These raw execution records are not required in the Git repository. The committed evaluation runner, scenarios, automated tests, documentation, and this verification record provide the reproducible methodology and summarized acceptance evidence.

**Final Developer actions:** Review the complete implementation diff, record formal acceptance, and update `v1.md` only after approval. Git operations remain under Developer control.
