# S12 — AI Appointment operations

**Status:** Planned  
**Depends on:** S04, S05, S06, S07, S08, S10, S11  
**Covers:** FR-04; FR-05; FR-06; FR-07; FR-08; FR-14; BR-07; BR-10; BR-13

## Goal

Extend the existing Laravel AI SDK conversational agent to register Customers, view their Business-scoped Appointments, and create, reschedule, or cancel Appointments through existing authoritative Laravel capabilities. Each Appointment-changing operation requires explicit natural-language confirmation of a persisted, customer-visible proposal before execution.

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

Use one minimal persistent proposal entity for `create`, `reschedule`, and `cancel`. Persist the Conversation, originating AI turn, operation type, immutable canonical payload, canonical presentation details, state, accepted confirmation-request message (when present), confirming turn (when present), durable execution outcome (when present), and timestamps. Avoid a generic workflow engine or unrelated state fields.

| State       | Meaning                                                                                               |
| ----------- | ----------------------------------------------------------------------------------------------------- |
| `prepared`  | Proposal saved; its confirmation request has not been accepted/persisted by S11.                      |
| `pending`   | Canonical confirmation request accepted and persisted; awaiting a subsequent Customer response.       |
| `completed` | The authoritative Appointment operation completed and its result was persisted.                       |
| `rejected`  | Proposal declined, replaced, invalidated by Conversation replacement, or definitively non-executable. |

- **Exactly one active proposal (`prepared` or `pending`) per Conversation**, enforced with a PostgreSQL partial unique index. Historical terminal proposals remain available.
- Every new proposal atomically transitions the existing active proposal to `rejected` **and** inserts its own `prepared` record. A proposal's operation type and canonical parameters never change. Replacing an unpresented `prepared` proposal also invalidates it.
- Idempotent retry of the same originating AI turn reuses its existing proposal; if a retry tries to prepare different parameters for that same turn, it must not silently replace them. Constrain the originating-turn association accordingly. A replacement initiated by a _new_ turn is a new proposal.
- The old proposal stops being confirmable immediately upon replacement. If the new assistant generation fails, its `prepared` proposal is recoverable; the old one does not return to `pending`.
- Promote `prepared → pending` **in the same transaction** that S11 accepts/persists the assistant confirmation request and completes its originating turn. A failed or stale completion cannot promote a proposal. A `prepared` proposal cannot be executed.
- New Conversations do not inherit old proposals or history. S10's configured inactivity threshold (initially 12 hours) remains the only Conversation timeout; no separate timer or cleanup job. Nonterminal proposals of superseded Conversations are ineligible for execution and can be marked `rejected` through a guarded invalidation path. Completed results remain intact.
- An inbound confirmation accepted into an existing Conversation remains valid for processing even if 12 hours elapse while AI is running, unless a newer Conversation actually supersedes it; do not add an elapsed-time check during provider execution.

### Presentation and confirmation

- First establish the exact target Appointment, Service, selected Professional, date/time, and other required data. A new creation requires Customer selection of an eligible Professional; **rescheduling retains the current Professional** unless the Customer requests a change, consistent with S07. Never silently substitute a Professional.
- Check candidate creation availability through the existing scheduling capabilities. A rescheduling candidate must use S07's own-Appointment occupancy exclusion; do not treat the original Appointment as a competing booking. Availability checks for presentation are informational and do not hold the slot.
- Present a **canonical confirmation question derived from the persisted proposal**, including the relevant action, Service, Professional, and date/time (and old/new details for rescheduling). Do not allow an unconstrained model-generated summary to disagree with the stored payload. A small backend formatter is sufficient; no generic templating engine.
- Explicit natural-language confirmation is mandatory for creation, rescheduling, and cancellation; no operation code or button is required. The model interprets acceptance, refusal, and ambiguity, but Laravel validates the exact stored proposal and authoritative rules.
- Confirmation must be a **subsequent persisted inbound Customer message**, belonging to the same active Conversation, with a message ID later than the accepted assistant confirmation-request message ID. A preemptive “yes” received before the question was accepted cannot authorize the operation, even if its turn is processed afterward.
- On affirmative confirmation, the model invokes the confirmation operation without supplying fresh Service, Professional, start time, Appointment, Customer, or Business parameters. Conditional acceptance (for example, “yes, but at 11”), ambiguity, or a changed preference must not execute the previous proposal. A changed selection is prepared as a new proposal and requires its own later confirmation.
- If the Customer declines, reject the proposal without changing an Appointment. On a definitive business conflict at execution (for example, the slot was taken), reject it, explain the result, and discuss alternatives; each accepted alternative is a new proposal with a new confirmation.
- Confirmations guarantee a request was **accepted and stored by S11**, not delivered by an external messaging provider. Delivery belongs to S13.

### Authorized writes, atomicity, and recovery

- Extend the existing S11 ownership mechanism narrowly so tool-triggered writes execute under a current `AiTurnClaim`, participant-scoped synchronization, and current-Conversation check. A stale claim must not initiate a write. Preserve the established lock order: participant advisory lock, turn validation, proposal row, then existing Appointment locks when applicable.
- Keep OpenAI calls outside transactions and locks. Execute proposal-state checks, authoritative S05/S07/S08 capability calls, Appointment audit, and durable operation-result persistence within one coherent short PostgreSQL transaction. For registration, protect the current Conversation association and use S04's existing uniqueness guarantee.
- The authoritative capability revalidates Customer ownership, eligibility, appointment status, date/time, and availability at execution. S05/S07 already handle occupancy/exclusion conflicts; S08 controls cancellation. Supply `actorType`, `actorId`, and `source` from trusted Laravel context, never model input. Do not duplicate scheduling or audit logic in tools.
- Existing capabilities open `DB::transaction()` themselves. Verify actual nested-savepoint behavior, `attempts: 3`, exception handling, PostgreSQL `23P01`, and rollback under the outer S12 transaction before relying on atomicity or automatic retry.
- Persist success and the corresponding proposal `completed` result atomically, including the confirming turn association. Recover a completed result by **proposal/confirming turn** after an AI or response-persistence failure; do not rerun the Appointment capability, recheck occupied availability as if the prior success failed, or depend on replayed SDK tool traces.
- Handle repeated confirmations, repeated tool calls, and overlapping provider attempts without duplicate writes or audits. A completed proposal is terminal. A separate later “yes” cannot execute the same operation again.
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

1. Create the proposal migration, model, and application capability; use canonical typed operation payloads, immutable parameters, and references to the originating turn and accepted prompt.
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

**Goal:** Prepare canonical Appointment actions and atomically accept the customer-visible confirmation request without changing an Appointment.

**Implementation**

1. Implement validated preparation tools for create, reschedule, and cancel using trusted scope, resolved Appointment references, eligibility, and availability. Reuse the S07 `CalculateAvailability::forRescheduling` path (or its existing equivalent) for self-exclusion rather than the general read-only availability tool. Preserve explicit Professional selection.
2. Render the confirmation request from the persisted proposal's canonical details. When a turn prepared a proposal, the accepted assistant content must use this backend-rendered request instead of unconstrained model prose; it cannot present a different action, Professional, or date/time.
3. Extend S11 turn completion minimally so accepting/persisting that assistant message and `prepared → pending` occur in the **same transaction** under current claim ownership.
4. Recover `prepared` proposals when the originating turn is retried. A stale generator cannot promote one or create a replacement after losing ownership.

**Acceptance and tests**

- Preparation performs no Appointment mutation; missing/ambiguous references ask for clarification.
- Create requires explicit Professional selection; reschedule defaults to the existing Professional unless a change is requested.
- A canonical prompt is persisted with its proposal. Failed assistant insertion or stale completion leaves no falsely `pending` proposal.
- A proposal becomes confirmable only after S11 accepted the request. Tests cover exact timestamps, DST, alternative Professionals, S07 exclusion, canonical wording, provider interruption, and repeated generation.

**Stop after Task 4.**

### Task 5 — Confirmed execution and idempotent result recovery

**Goal:** Execute an accepted, confirmed proposal exactly once at the authoritative application boundary and preserve recoverable results.

**Implementation**

1. Locate the active `pending` proposal from trusted Conversation context. Verify current claim and Conversation, the accepted prompt reference, and inbound-message ID ordering (`confirmation inbound ID > confirmation request message ID`).
2. Handle explicit decline without Appointment mutation. For affirmative confirmation, execute only the persisted proposal parameters; do not accept new operation parameters through the tool.
3. Under participant/turn/proposal locking and a coherent transaction, invoke S05/S07/S08 as appropriate, persist the authoritative outcome and confirming-turn reference, and transition to `completed` or definitive `rejected`.
4. Make repeated tool calls, repeated confirmation messages, and reattempts of the confirming turn read the durable result. Surface that result to `ConversationTurnProcessor` on retry **before** model generation, so later responses cannot infer failure from missing SDK tool traces or reexecute a committed write.
5. Verify nested Laravel transactions, savepoints, deadlock/serialization behavior, exclusion conflicts, and exception boundaries against the actual existing capabilities.

**Acceptance and tests**

- No mutation without a customer-visible `pending` proposal and a **later persisted** affirmative message. A “yes” sent before the prompt was accepted cannot confirm it.
- Claim loss, Conversation replacement, wrong Customer/Business, duplicate confirmations, and concurrent attempts cannot cause unauthorized or duplicate writes/audits.
- A slot taken between proposal and execution produces a definitive failure, rejects that proposal, and leaves the Appointment system consistent; a new alternative needs fresh confirmation.
- Rescheduling preserves the old slot on failure; cancellation obeys current eligibility and late-notice audits; no-op reschedule is reported without inventing a change.
- A successful Appointment commit followed by provider or assistant-message failure is recovered as success with no second domain mutation. Technical errors roll back and remain retryable.
- Use real PostgreSQL for concurrency, `23P01`, savepoint, rollback, and recovery tests.

**Stop after Task 5.**

### Task 6 — Agent integration and multi-turn behavior

**Goal:** Integrate the new tools, Customer state, durable proposals, and recorded outcomes into the single existing agent.

**Implementation**

1. Extend `ConversationAgent` tool registration and instructions, retaining S11 runtime Business context, external completed-turn history, and `CheckAvailabilityTool`.
2. Inject trusted execution context and fresh Customer/proposal state. Handle registration-to-booking continuity without requiring repeated information or a new Conversation.
3. Guide natural-language intent interpretation: explicit approval, decline, clarification, changed parameters, Appointment selection, eligibility conflicts, and failures. Never let the model bypass backend authorization.
4. On retries, provide a committed result from the current confirming turn as authoritative context; avoid repeated execution. Treat a new Conversation as a fresh interaction while retaining persistent Customer/Appointments.
5. Keep customer-facing confirmations concise, canonical, and truthful. No SDK-native Conversation persistence or new infrastructure.

**Acceptance and tests**

- Complete create/view/reschedule/cancel flows work for registered Customers; unregistered Contacts can register and continue booking in the same Conversation.
- Create, reschedule, and cancel require separate explicit natural-language confirmation; negative, conditional, ambiguous, and premature replies never execute the previous proposal.
- Changed selections atomically replace the old proposal; only the latest active proposal can be confirmed.
- Availability, ownership, current Business configuration, and durable operation results determine answers; previous S11 read enquiries still work.
- SDK-fake tests cover tool wiring, fresh identity, history, expiration, outcome handling, and all branching cases. Fakes do not establish live-model semantic reliability.

**Stop after Task 6.**

### Task 7 — Full verification and Developer acceptance

**Goal:** Validate the integrated behavior and regressions before marking S12 complete.

**Automated verification**

1. Run the complete backend test suite and repository-required formatting, lint, and static-analysis checks.
2. Run targeted S04–S08, S10–S11 regression tests, plus real-PostgreSQL integration and concurrency tests from Tasks 1–6.
3. Report any test environment limitations and unresolved implementation risks; do not weaken tests to pass.

**Live OpenAI verification — [Developer]**

Validate bounded real conversations for: unregistered Customer registration followed by booking (including optional-phone refusal); registered Customer listing, creation, rescheduling, and cancellation; mandatory confirmation for all three writes; natural affirmative/negative/conditional/ambiguous replies; changing the proposed Professional or time; an occupied slot at confirmation; multiple existing Appointments; a new Conversation after inactivity; and S11 descriptive/availability regressions.

Check authoritative Customer, Conversation, proposal, Appointment, and audit records. Provider responses alone are not evidence of success.

**Completion criteria**

- All S12 acceptance criteria and relevant prior-slice regressions pass.
- The Developer reviews the changes, executes the marked live scenarios, and accepts the implementation.
- Update `v1.md` only after acceptance. The Developer controls Git operations.

---

## Scope boundaries

S12 excludes WhatsApp/n8n transport (S13), web/demo endpoints, Business Admin UI, calendar sync, notifications and reminders, new authentication methods, automatic Professional assignment, fixed slot grids, persistent AI semantic memory, new queues/schedulers, and generic workflow infrastructure. Existing Laravel capabilities and PostgreSQL remain authoritative.

## Implementation references

- `docs/product.md`: covered FR/BR sections listed above.
- `docs/architecture.md`: Laravel AI, identity/security, PostgreSQL and integration boundaries.
- `docs/adr/0001-pragmatic-modular-monolith.md`
- `docs/adr/0002-authoritative-state-postgresql.md`
- `docs/adr/0004-laravel-ai-sdk.md`
- Existing accepted S04–S08, S10, and S11 slice plans under `docs/plans/v1/slices/`.
- Repository `AGENTS.md` and backend-specific committed rules for each implementation task.
