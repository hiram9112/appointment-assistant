# S11 — AI read capabilities

**Status:** Active  
**Depends on:** S01, S02, S03, S10  
**Covers:** FR-01; FR-02; FR-03; FR-08

## Goal

Enable natural-language Business, Service, Professional, and Appointment availability enquiries through Laravel AI SDK and the existing authoritative Laravel capabilities.

Support coherent multi-turn interactions using S10 Conversation history without duplicating persistent conversation storage or moving Business rules into the AI layer.

---

## Design decisions

### AI integration

Use Laravel AI SDK with OpenAI as the V1 provider, following ADR-0004.

Implement one conversational agent with four read-only tools:

| Tool                         | Existing capability                  |
| ---------------------------- | ------------------------------------ |
| `GetBusinessInformationTool` | `GetBusinessInformation`             |
| `ListServicesTool`           | `ListServices`                       |
| `GetServiceInformationTool`  | `GetServiceInformation`              |
| `CheckAvailabilityTool`      | Existing S03 availability capability |

Tool names may follow equivalent existing repository conventions.

The agent interprets natural language and selects tools. Tools validate arguments and delegate to existing capabilities.

Business identity comes from trusted application context, never from model-selected identifiers.

The AI layer must not independently calculate availability, infer Professional eligibility, invent unavailable information, or modify authoritative application state.

### Conversation history

Keep S10 as the authoritative Conversation and message store.

Use the SDK's supported external-history mechanism, preferably `withMessages()`, after verifying the installed version.

Do not use `RemembersConversations` or create SDK-managed Conversation persistence.

For each AI turn:

1. Retrieve the relevant completed earlier turns from the active S10 Conversation.
2. Convert their persisted messages into SDK-compatible history.
3. Preserve logical turn ordering.
4. Exclude the current inbound message from supplied history.
5. Pass the current inbound content to `prompt()`.
6. Persist the accepted assistant response through S10.

Messages belonging to later turns must not enter the current turn's context.

Previous Conversations are not automatically included after inactivity expiration.

S10 stores user and assistant text rather than structured tool-execution history. This remains the default for S11.

If a later request requires facts absent from persisted text, the agent must retrieve authoritative information again or request clarification.

Do not introduce structured transcript persistence or semantic summaries without a demonstrated need.

If the model context budget is exceeded, handle the limitation explicitly rather than silently discarding information required for conversational continuity.

### Natural-language interpretation

The agent must support the FR-08 behavior defined in `product.md`, including:

- colloquial and incomplete requests;
- alternative Service names;
- context-dependent references;
- relative dates and times;
- changed preferences;
- topic changes;
- clarification when necessary.

Interpret relative dates using the Business timezone and a testable operation time.

The agent interprets expressions such as "tomorrow afternoon"; the availability tool validates the resulting structured date/time range and delegates scheduling to S03.

Do not introduce a separate natural-language date parser in the tools.

Service and Professional references must resolve against authoritative Business configuration.

Preserve explicitly requested Professionals and the existing S03 rules for alternatives and non-prioritized Professional choices.

### AI-turn coordination

S10 provides durable inbound-message persistence, idempotency, and participant-scoped synchronization. It does not coordinate complete AI generations.

S11 must additionally guarantee:

- one logical AI turn per persisted inbound message;
- at most one accepted assistant response per completed turn;
- ordered processing of distinct turns for the same participant;
- independent processing across unrelated participants;
- recoverable provider failures and interrupted processing;
- rejection of stale processing attempts;
- no reactivation of superseded Conversations by delayed responses.

Use the smallest durable turn representation and coordination mechanism compatible with the existing Laravel/PostgreSQL implementation.

Do not hold database transactions or locks open during external provider calls.

A timeout or expired processing claim does not guarantee that a previous OpenAI request has stopped. Only the currently authorized attempt may commit a result.

Provide an explicit internal mechanism to resume eligible pending or failed turns. Do not assume autonomous processing without a configured execution trigger.

Preserve S10's inactivity semantics: elapsed time alone does not expire a Conversation. Expiration is evaluated when a new inbound message arrives.

### Tool execution context

The SDK may execute multiple read-only tools during one generation.

S11 persists the accepted final assistant response, not every internal tool step.

This decision must be validated against representative multi-turn scenarios.

If text-only history proves insufficient, report the failing scenario and propose the smallest persistence extension for Developer approval.

S12 write-operation recovery and idempotency remain outside this slice.

### Verification

Use SDK fakes for normal automated tests and real PostgreSQL for concurrency tests.

Fake provider responses verify integration contracts, not actual model comprehension.

Natural-language behavior requires a bounded manual OpenAI smoke test marked `[Developer]`.

---

## Scope boundaries

S11 excludes:

- Customer registration and Appointment retrieval or modification through AI;
- write tools, write confirmations, and Appointment mutation recovery;
- WhatsApp/n8n transport and HTTP chat endpoints;
- public demo and Business Admin interfaces;
- SDK-native Conversation storage;
- semantic memory, RAG, speculative tool transcripts or summaries;
- new scheduling algorithms or fixed booking grids;
- notifications, reminders, and Calendar synchronization;
- generalized queues, workflow engines, or locking frameworks.

---

# Task 1 — SDK foundation and external-history proof

## Goal

Install Laravel AI SDK and prove compatibility with S10's existing persistent history without enabling SDK-native Conversation storage.

## Implementation

1. Verify actual PHP, Laravel, and dependency versions.
2. Install and lock a compatible Laravel AI SDK version.
3. Configure the OpenAI provider and model using environment-backed Laravel configuration.
4. Inspect the installed SDK's Agent, message, history, and fake APIs.
5. Create a minimal agent skeleton.
6. Implement the smallest adapter that maps S10 user/assistant messages into SDK-compatible history.
7. Verify that the current inbound message is supplied exactly once.
8. Confirm that SDK-native Conversation persistence remains disabled.

Use either `withMessages()` or `Conversational`, not both.

Do not implement AI tools, full agent behavior, or turn coordination.

## Acceptance criteria

- SDK installation and dependency resolution succeed.
- An agent can consume S10 history through a supported API.
- Message roles and ordering are preserved.
- The current inbound message is not duplicated.
- No additional Conversation persistence is introduced.
- No live OpenAI request is required for automated validation.

## Tests

Cover:

1. Agent execution with a fake provider.
2. External-history mapping and ordering.
3. Current-message exclusion.
4. Business-scoped history isolation.
5. No SDK-native Conversation persistence.
6. S10 regression compatibility.

Run the applicable backend checks.

**Do not implement Task 2.**

---

# Task 2 — Read-only AI tools

## Goal

Expose the existing S01–S03 read capabilities through validated Laravel AI SDK tools.

## Implementation

Implement:

- `GetBusinessInformationTool`
- `ListServicesTool`
- `GetServiceInformationTool`
- `CheckAvailabilityTool`

Follow installed SDK tool contracts and existing Laravel dependency-injection conventions.

### Shared tool requirements

- Receive Business identity from trusted application context.
- Define and validate model-supplied argument schemas.
- Delegate to existing application capabilities.
- Return structured authoritative results or explicit invalid/ambiguous/unavailable outcomes.
- Never infer missing Business data.
- Never perform authoritative writes.

### Business information

Reuse `GetBusinessInformation` without duplicating Business configuration logic.

### Services and Professionals

Reuse `ListServices` and `GetServiceInformation`.

Resolve requested references using the applicable Business catalogue and configured Professional eligibility.

Support ambiguous references by returning suitable candidates for conversational clarification.

Do not build a separate catalogue, matching framework, or Professional prioritization mechanism.

### Availability

Reuse the established S03 capability and its current PostgreSQL occupancy integration.

The tool receives structured Service, Professional, and bounded temporal arguments.

Validate:

- Service and Professional references;
- eligible Professional selection;
- date/time validity;
- Business-local timezone interpretation;
- scheduling request boundaries.

Pass validated inputs to S03.

Return the existing valid Appointment start ranges associated with their Professionals.

Do not invent fixed slots, calculate scheduling rules, or silently replace a requested Professional.

## Acceptance criteria

- All four tools invoke the intended capabilities.
- Missing, inactive, invalid, ambiguous, and cross-Business references are handled safely.
- Service prices, durations, and eligible Professionals reflect authoritative configuration.
- Availability results preserve S03 scheduling behavior and Professional choices.
- All tools are read-only.

## Tests

Cover:

1. Configured and missing Business information.
2. Active/inactive Services and nullable/free prices.
3. Service details and eligible Professionals.
4. Unknown, ambiguous, and cross-Business references.
5. Professional display names and eligibility.
6. Valid/invalid date ranges and timezone boundaries, including DST.
7. Explicit Professional unavailable and valid alternatives.
8. Availability with existing occupancy and buffers.
9. Preservation of S03 start ranges without artificial grids.
10. No writes or duplicated scheduling calculations.

Run relevant S01–S03 regression tests.

**Do not implement Task 3.**

---

# Task 3 — Durable AI-turn coordination

## Goal

Implement reliable AI-turn processing on top of S10, without coupling database transactions to OpenAI latency.

This task establishes processing guarantees independently from the final conversational agent.

## Implementation

### Turn persistence

Introduce the smallest durable representation linking an inbound `ConversationMessage` to its processing outcome.

It must support:

- unique inbound-turn association;
- pending, processing, completed, and recoverable failure states or equivalents;
- current processing ownership;
- accepted assistant-message association;
- required timestamps.

A superseded or otherwise non-completable outcome may be represented where necessary.

Choose the minimal schema and application contracts based on existing conventions.

Do not modify committed S10 migrations.

### Registration and idempotency

Repeated inbound deliveries must resolve to the same logical turn.

Turn registration and inbound persistence must be atomic or have a reliable reconciliation path if interrupted between those operations.

A completed turn must not generate a second accepted response.

### Processing order

Coordinate processing by Business, channel, and participant identity.

Distinct turns for one participant must be processed in logical inbound order.

A later turn cannot bypass an unresolved earlier turn and generate using incomplete context.

Independent participants must not be unnecessarily serialized.

Provide explicit outcomes when processing is pending, already running, completed, or temporarily unable to proceed.

### Claiming and recovery

Use short PostgreSQL transactions and existing Laravel conventions.

Claim eligible work before invoking the generation operation.

Do not hold locks across external execution.

Support controlled retry of failed or interrupted work.

The processing claim must prevent an older attempt from committing after another attempt becomes authoritative.

If leases are used, expiration alone must not be treated as proof that the old execution stopped.

Provide an explicit internal retry/re-entry path. No queue worker or scheduler is required in this slice.

### Completion

On successful generation:

1. Validate current processing ownership.
2. Validate the Conversation's current lifecycle relationship to its participant.
3. Reject a stale completion that would revive an already superseded Conversation.
4. Persist the assistant message through S10.
5. Atomically mark the turn completed and associate the assistant message.

Coordinate the completion check with inbound Conversation lifecycle changes so a concurrent new Conversation cannot invalidate the result between validation and persistence.

Do not reject an otherwise valid response solely because 12 hours elapsed during generation.

### Failures

A provider failure must:

- preserve the inbound message;
- avoid fabricated assistant success;
- leave the turn recoverable where applicable.

A failed assistant insertion must roll back turn completion.

S11 guarantees one accepted persistent assistant result, not exactly-once external provider execution.

### History ordering

Expose the turn associations needed to build later AI context from completed earlier turns.

Preserve each user message and its accepted assistant response in logical turn order, excluding later or unresolved turns.

Use an injectable fake generation operation to test the coordinator without integrating the complete agent yet.

## Acceptance criteria

- Inbound messages have unique logical turns.
- Duplicate deliveries do not produce duplicate completed responses.
- Distinct turns preserve ordering.
- Independent participants can progress concurrently.
- Provider execution happens outside database transactions.
- Failed/interrupted processing is recoverable.
- Stale attempts cannot commit accepted results.
- Assistant persistence and turn completion are atomic.
- Superseded Conversations cannot be revived by late replies.
- Existing S10 inactivity behavior remains unchanged.
- Pending work has an explicit progress/retry path.

## Tests

Cover:

1. First-turn registration and repeated delivery.
2. Duplicate delivery before, during, and after processing.
3. Concurrent distinct messages and FIFO ordering.
4. Independent-participant concurrency.
5. Failure and controlled retry.
6. Interrupted registration or claim recovery.
7. Stale completion after claim replacement.
8. Late response after a new Conversation is created.
9. Long-running response without Conversation replacement.
10. Atomic assistant persistence and rollback.
11. Correct history associations.
12. Business/participant isolation.
13. Relevant multi-process PostgreSQL races.
14. S10 lifecycle and concurrency regressions.

Run the complete backend suite and mandatory lint checks.

**Do not implement Task 4.**

---

# Task 4 — Conversational agent integration

## Goal

Connect the read-only AI agent to the four tools, S10 history, and Task 3 turn processing.

## Implementation

### Agent behavior

Configure the agent to:

- interpret natural-language Business and scheduling requests;
- invoke authoritative tools for factual information;
- reuse relevant active Conversation context;
- recognize changed preferences and topic changes;
- clarify incomplete or ambiguous requests;
- preserve explicitly selected Professionals;
- avoid fabricated Business facts or available times.

Prompts must describe general behavior, not hardcode Lúmina-specific configuration.

The agent interprets natural-language expressions. Tools validate structured arguments and delegate authoritative operations.

### Context integration

Use Task 1's external-history adapter and Task 3's turn associations.

For each turn:

1. Load the current S10 Conversation.
2. Retrieve completed earlier turns in logical order.
3. Map their persisted user/assistant messages into SDK history.
4. Exclude current and later inbound messages.
5. Invoke the agent with the current inbound content.
6. Submit the final response to the coordinator's authorized completion path.

Do not assume raw message insertion order always represents complete conversational turn order.

### Multi-turn behavior

Support contextual follow-ups about previously discussed:

- Services, prices, and durations;
- eligible Professionals;
- dates and availability;
- alternative choices;
- changed requests.

When text history does not reliably identify a reference, retrieve authoritative information again or ask for clarification.

Do not fabricate remembered tool results.

### Failure handling

Use Task 3's recovery behavior.

Provider failures must not produce a successful assistant response.

Expected clarification, invalid references, and unavailable scheduling information remain distinct from technical failures.

## Acceptance criteria

- A single agent exposes the four read-only tools.
- The agent consumes the correct active S10 context.
- Current and later messages are not duplicated or leaked into prompts.
- Responses use authoritative application results.
- Follow-ups, changes, and clarifications are supported.
- Agent execution uses the durable coordinator.
- No Appointment-changing tools or duplicate Conversation store are introduced.

## Tests

Use SDK fakes to verify:

1. Agent and tool registration.
2. Tool invocation wiring.
3. External-history reconstruction.
4. Current-message exclusion and logical ordering.
5. Context isolation after Conversation expiration.
6. Correct assistant persistence.
7. Provider failure behavior.
8. Business and participant isolation.
9. Absence of write tools.

Do not treat fake responses as proof of linguistic interpretation.

Run complete backend validation.

**Do not implement Task 5.**

---

# Task 5 — Conversational acceptance and regression

## Goal

Validate S11 against FR-01, FR-02, FR-03, and FR-08 using realistic multi-turn scenarios and the reference Business.

## Implementation

### Automated integration tests

Verify the complete application path:

S10 inbound persistence → AI-turn coordination → SDK agent → read-only tools → authoritative capabilities → assistant persistence → subsequent context.

Cover at least:

1. Business information, including missing configuration.
2. Service catalogue, price, duration, and Professional follow-ups.
3. Availability with explicit and unspecified Professional preferences.
4. Clarification followed by continuation.
5. Changes of Service, Professional, or date.
6. Topic changes and context-dependent references.
7. Inactivity expiration without previous-context inheritance.
8. Duplicate and concurrent inbound messages.
9. Provider failure and recovery.
10. Correct Business isolation and read-only behavior.
11. No SDK-native persistent memory.
12. No regression of S01–S03 or S10.

Use SDK fakes for provider integration and exercise the actual tools and application capabilities directly.

### Manual OpenAI verification — [Developer]

Using the selected model and Lúmina reference data, validate a bounded Spanish-language smoke matrix covering:

- colloquial Service requests and alternative names;
- incomplete or ambiguous sentences;
- relative dates and Business-local times;
- references such as "the second Service";
- explicit Professional preferences;
- unavailable Professionals and alternatives;
- changes of mind;
- topic changes and continuity;
- clarification without unnecessary repetition.

Verify tool selection, argument interpretation, and factual responses.

Record the outcomes and any concrete failure.

Do not commit credentials or sensitive transcripts.

If a scenario fails specifically because text-only history loses essential tool context, document the evidence and request approval before introducing structured transcript persistence.

### Regression validation

Run the complete backend suite, mandatory lint/static checks, and relevant concurrency tests.

Confirm that S11 has not modified Appointment rules, Customer identity semantics, or S10 lifecycle guarantees.

## Acceptance criteria

- FR-01, FR-02, and FR-03 requests use the established authoritative capabilities.
- FR-08 conversational continuity and clarification work in the agreed scenarios.
- Business timezone and Professional preferences are respected.
- Missing or unavailable information is not fabricated.
- Complete AI turns are ordered, idempotent, and recoverable.
- Technical failures do not generate false success.
- No S12 or unrelated functionality is introduced.
- Automated checks pass.
- Manual provider validation is completed or explicitly recorded as unverified.

---

# Slice acceptance

S11 is complete when:

- Laravel AI SDK is integrated with OpenAI and S10 history;
- the four read-only tools use existing application capabilities;
- authoritative Business and scheduling rules remain in Laravel;
- natural-language enquiries and multi-turn context satisfy the agreed acceptance scenarios;
- AI-turn coordination prevents duplicate accepted responses and stale Conversation reactivation;
- failure recovery and relevant concurrency guarantees are validated;
- S10 remains the only persistent Conversation store;
- all required tests, lint checks, and manual acceptance actions are completed or explicitly documented.

The Developer reviews and accepts the implementation before marking S11 as `Done`.

The Agent must not create a Git commit.

---

## References

Consult only the applicable sections of:

- `docs/product.md` — FR-01, FR-02, FR-03, FR-08.
- `docs/plans/v1/v1.md` — S11.
- `docs/plans/v1/slices/S10-conversation-foundation.md`.
- `docs/architecture.md` — AI and application boundaries.
- `docs/adr/0004-laravel-ai-sdk.md`.
- Existing S01–S03 capability contracts.
- Official Laravel AI SDK documentation and installed package source.

Follow `AGENTS.md` and applicable backend rules. Do not redesign unrelated slices.
