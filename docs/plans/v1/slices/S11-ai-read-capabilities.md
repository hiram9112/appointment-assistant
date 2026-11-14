# S11 — AI read capabilities

**Status:** Active  
**Depends on:** S01, S02, S03, S10  
**Covers:** FR-01; FR-02; FR-03; FR-08

## Goal

Enable natural-language Business, Service, Professional, and Appointment availability enquiries through Laravel AI SDK and existing authoritative Laravel capabilities.

Provide the agent with current, customer-facing Business information as runtime context. Keep actual Appointment availability behind a validated read-only tool.

Support multi-turn interaction through S10 Conversation history without introducing another persistent conversation store.

---

## Design decisions

### AI integration

Use Laravel AI SDK with OpenAI as the V1 provider, following ADR-0004.

Implement one conversational agent using:

- Stable instructions defining assistant behavior.
- Fresh runtime Business context from Laravel application capabilities.
- Active Conversation history from S10.
- One read-only `CheckAvailabilityTool`.

The agent interprets Customer requests and may answer descriptive questions directly from runtime context.

Laravel remains authoritative for Business configuration, Professional-Service eligibility, availability, validation, and persistence.

Business identity is supplied by trusted application code, never selected by the model.

### Runtime Business context

Build compact, structured context using:

- `GetBusinessInformation`;
- `ListServices`.

Include the customer-facing information needed for FR-01 and FR-02:

- Business profile, contact information, timezone, operating hours, payment methods, and configured customer-facing policies.
- Active Services with canonical IDs, names, descriptions, durations, prices, and currencies.
- Eligible Professionals with canonical IDs and customer-facing display names, preserving Service eligibility relationships.

Use existing application DTOs rather than introducing a separate data-access layer.

Preserve null, zero, false, and missing values correctly. Do not invent defaults.

Avoid unnecessary data duplication, especially repeated Professional records.

Do not include Appointment occupancy, scheduling buffers, internal Professional schedules, Customer information, or unrelated persistence fields.

The context is generated for each agent execution from current application data. It is not persisted as Conversation state, cached across turns, or hardcoded in static prompts.

Separate stable instructions from runtime data. Treat Business-provided names and descriptions as untrusted content, not executable instructions.

General operating hours may be explained to Customers, but must never be presented as confirmed Appointment availability.

### Availability tool

Expose one read-only AI tool:

`CheckAvailabilityTool`

The tool receives:

- Canonical Service ID.
- Optional canonical Professional ID.
- Structured, bounded date/time arguments.

The agent identifies the intended Service and Professional using runtime Business context.

The tool validates generated IDs against current authoritative Business data before delegating to the existing S03 availability capability.

Use straightforward SDK argument schemas. Do not duplicate the catalogue inside dynamic tool enums or introduce text-substring matching.

Unknown, inactive, or cross-Business references must fail safely.

The tool must preserve S03 behavior, including:

- Professional-Service eligibility;
- explicit Professional preferences;
- available alternatives;
- scheduling occupancy and buffers;
- valid Appointment start ranges;
- Business timezone and DST handling;
- `from == to` as a valid exact-start enquiry.

Do not independently calculate availability or generate artificial fixed slots.

Return structured results distinguishing:

- `ok`;
- `unavailable`;
- `requested_professional_unavailable`;
- `requested_professional_ineligible`;
- invalid or unknown references;
- invalid temporal arguments.

An explicitly requested Professional's ineligibility or unavailability takes precedence over the presence of alternatives.

For a known, Business-owned but ineligible Professional, preserve S03's requested-Professional result and available alternatives rather than rejecting the request prematurely.

Ambiguous natural-language references are handled by the agent through clarification, not by guessing an ID inside the tool.

Professional names and display names are not unique identifiers.

### Conversation context

S10 remains the authoritative Conversation and message store.

Use the external-history integration established in Task 1, without SDK-native Conversation persistence.

The agent receives:

1. Stable instructions.
2. Current runtime Business context.
3. Completed earlier turns from the active S10 Conversation.
4. The current inbound message exactly once.
5. The available tool definitions.

Task 3 establishes completed-turn ordering. Task 4 integrates that ordering with the SDK.

Previous Conversations are not automatically included after inactivity expiration.

Store accepted assistant responses through S10. Do not persist runtime Business context, SDK tool traces, or separate semantic state.

When earlier conversation text cannot reliably establish a reference, the agent must clarify rather than invent missing information.

### AI-turn coordination

S10 handles inbound persistence and message idempotency. S11 adds coordination of complete AI executions.

Required guarantees:

- One logical AI turn per inbound message.
- At most one accepted assistant response per completed turn.
- Ordered processing for the same participant.
- Independent processing across participants.
- Recovery of failed or interrupted processing.
- Rejection of stale processing attempts.
- No late-response reactivation of superseded Conversations.

Use the smallest durable coordination mechanism supported by the existing Laravel/PostgreSQL architecture.

Do not keep database transactions or locks open during provider calls.

Only the currently authorized attempt may commit a response.

Preserve S10's inactivity semantics and provide an explicit internal recovery/re-entry mechanism without introducing speculative background infrastructure.

### Verification

Use Laravel AI SDK fakes for ordinary automated tests and real PostgreSQL for relevant concurrency tests.

Fakes verify integration contracts, not actual natural-language interpretation.

Validate real conversational behavior separately through a bounded OpenAI smoke test marked `[Developer]`.

---

## Scope boundaries

S11 excludes:

- Customer registration and AI Appointment management operations;
- write tools and write-operation recovery;
- WhatsApp/n8n transport and public HTTP chat endpoints;
- Business Admin interfaces;
- SDK-managed Conversation storage;
- persistent semantic memory, RAG, embeddings, or alias infrastructure;
- new scheduling algorithms or fixed booking grids;
- notifications, reminders, and external calendar synchronization;
- generalized workflow or queue infrastructure.

Existing S01–S03 and S10 application contracts remain authoritative.

---

# Task 1 — SDK foundation and external-history proof

**Status:** Accepted — implemented.

## Goal

Integrate Laravel AI SDK and verify that it can consume S10 history without enabling SDK-native Conversation persistence.

## Implementation

1. Install and lock a compatible Laravel AI SDK release.
2. Configure OpenAI through environment-backed Laravel configuration.
3. Create the minimal `ConversationAgent`.
4. Implement the S10-to-SDK message-history adapter.
5. Preserve historical message roles and ordering.
6. Supply the current inbound message exactly once.
7. Verify integration with SDK fakes.

Use the supported external-history mechanism established during implementation.

Do not enable `RemembersConversations`.

## Acceptance criteria

- SDK installation and dependency resolution succeed.
- The agent consumes S10 history.
- The current message is not duplicated.
- Business-scoped history remains isolated.
- SDK-native Conversation persistence is not used.
- Automated validation passes without live OpenAI requests.

## Tests

Cover fake-agent execution, external-history mapping, current-message exclusion, Business isolation, and S10 regressions.

**No changes are required to Task 1 for the revised architecture.**

---

# Task 2 — Runtime Business context and availability tool

## Goal

Prepare authoritative runtime Business context and implement the single read-only tool required to calculate Appointment availability.

This task prepares both components independently. Connecting them to the conversational agent belongs to Task 4.

## Implementation

### Business context builder

Implement a small component, conceptually `BusinessContextBuilder`, using:

- `GetBusinessInformation`;
- `ListServices`.

Its input is a trusted Business.

Its output is a compact, deterministic, structured representation of the customer-facing facts defined in the Runtime Business context section.

Requirements:

- Use existing application DTOs and capability contracts.
- Include only active Services.
- Preserve canonical Service and Professional IDs.
- Represent Professional-Service eligibility explicitly.
- Use existing Professional display-name fallback behavior.
- Preserve exact decimal prices and distinguish zero from null.
- Avoid unnecessary duplication of Professionals.
- Handle empty catalogues and missing optional configuration.
- Avoid exposing unrelated or non-customer-facing data.
- Do not perform language interpretation, prompt construction, or Conversation persistence.

Do not modify the underlying S01/S02 capabilities.

### CheckAvailabilityTool

Implement the tool using the installed Laravel AI SDK Tool contract.

Define clear descriptions and schemas for canonical IDs and temporal inputs.

The Business must be injected from trusted application context.

Validate Service and Professional identity, ownership, and activity before invoking S03.

Allow S03 to determine eligibility and available alternatives for a known requested Professional.

Validate time arguments using the applicable Business timezone and the established operation-time rules.

Preserve exact-start queries and DST behavior.

Delegate scheduling entirely to S03 and return structured results with the agreed status precedence.

Do not add:

- `GetBusinessInformationTool`;
- `ListServicesTool`;
- `GetServiceInformationTool`;
- a textual `CatalogueReferences` matcher;
- dynamic catalogue enums.

If the installed SDK requires a different schema representation, follow its actual API without changing the tool's approved semantics.

### Documentation alignment

Update the following documentation as part of Task 2:

**`docs/product.md` — Section 6**

Verify the current repository version of the Business configuration principle.

Preserve the accepted distinction:

- Business-specific values must not be hardcoded in static prompts or application source code.
- Authoritative, Business-scoped data retrieved at runtime may be supplied as temporary, relevant AI context.
- Laravel remains responsible for validating model-generated references.

If this clarification already exists, do not duplicate it. Remove or generalize wording that unnecessarily restricts runtime grounding to dynamic tool schemas.

Do not change FR-01, FR-02, FR-03, or FR-08 unless a concrete contradiction remains.

**`docs/architecture.md` — AI boundary**

Update the applicable AI integration explanation to represent both supported paths:

- Laravel capabilities supply runtime descriptive Business context to the agent.
- AI tools invoke Laravel capabilities when authoritative operations are required.

Keep the existing separation between AI interpretation and Laravel Business rules.

Make a targeted change; do not rewrite the architecture document.

The current S11 plan already documents the revised design. Do not rewrite it again during implementation unless a verified implementation constraint requires correction.

Do not change ADR-0004, `v1.md`, or completed S01–S03 slice plans.

## Acceptance criteria

- Runtime context contains current authoritative Business, Service, and Professional information.
- IDs and eligibility relationships are preserved.
- Missing or unconfigured values are represented accurately.
- Different Businesses produce isolated context.
- Configuration changes are reflected when the context is rebuilt.
- The context builder does not implement or persist conversational behavior.
- `CheckAvailabilityTool` accepts canonical IDs and delegates to S03.
- Requested Professionals remain distinguishable from alternatives.
- Invalid references and temporal arguments fail safely.
- The tool performs no authoritative writes.
- Documentation reflects the accepted runtime-context design.

## Tests

Cover:

1. Business information, operating hours, payment methods, and nullable policies.
2. Active/inactive Services, descriptions, durations, and exact prices.
3. Professional IDs, display names, duplicate names, and eligibility relationships.
4. Empty catalogues and missing optional configuration.
5. Business isolation and refreshed context after configuration changes.
6. Unknown, inactive, and cross-Business Service IDs.
7. Unknown, cross-Business, available, unavailable, and ineligible Professionals.
8. Requested-Professional status precedence with and without alternatives.
9. Valid/invalid temporal arguments, including exact-start and DST cases.
10. S03 availability results, occupancy, buffers, and start ranges.
11. Absence of writes and duplicated scheduling logic.

Run focused tests, applicable S01–S03 regressions, the complete backend suite, and lint.

**Do not implement Task 3 or connect the completed agent.**

---

# Task 3 — Durable AI-turn coordination

## Goal

Coordinate AI processing reliably on top of S10 without keeping database transactions open during OpenAI calls.

Test coordination independently of the real conversational agent.

## Implementation

### Durable turns

Introduce the smallest persistent representation associating an inbound user message with its processing outcome.

Support:

- unique inbound-turn association;
- pending, processing, completed, and recoverable-failure states or equivalent outcomes;
- current processing ownership;
- accepted assistant-message association;
- necessary timestamps.

Do not modify committed S10 migrations.

### Ordering and claims

Repeated inbound deliveries must resolve to one logical turn.

Ensure atomic registration or an explicit recovery path when inbound persistence and turn registration cannot occur in one atomic operation.

Process distinct turns in participant order.

A later turn must not bypass an unresolved earlier turn.

Independent participants must not be unnecessarily serialized.

Use short transactions for claiming, state transitions, and completion.

Do not hold locks across provider calls.

Support controlled retries and reject stale completion attempts. An expired claim does not prove that an earlier provider request has stopped.

Provide an explicit internal mechanism to resume pending or failed work without requiring a new queue or scheduler.

### Completion

On successful generation:

1. Verify processing ownership.
2. Check that the Conversation has not been superseded.
3. Coordinate that check with S10's participant-scoped lifecycle synchronization.
4. Persist the assistant message through S10.
5. Atomically associate the message with the completed turn.

A delayed result must not reactivate a Conversation that has already been replaced.

Elapsed inactivity alone does not invalidate an otherwise current Conversation response.

### Failures and history

Preserve inbound messages after provider failure.

Do not fabricate successful assistant responses.

A failed assistant insertion must roll back turn completion.

Expose completed-turn associations for Task 4's logical history reconstruction.

S11 guarantees one accepted persisted result, not exactly-once provider execution.

## Acceptance criteria

- Duplicate inbound messages do not produce duplicate completed responses.
- Distinct turns preserve processing order.
- Independent participants can progress concurrently.
- Provider execution occurs outside database transactions.
- Failed/interrupted processing is recoverable.
- Stale attempts cannot commit accepted responses.
- Assistant persistence and completion are atomic.
- Superseded Conversations cannot be reactivated.
- S10 inactivity behavior remains unchanged.
- Pending work has an explicit re-entry path.

## Tests

Cover duplicate deliveries, concurrent messages, FIFO ordering, participant isolation, interrupted execution, retries, stale claims, Conversation replacement, long-running responses, atomic rollback, completed-turn associations, and relevant multi-process PostgreSQL races.

Run the complete backend suite, S10 regressions, and mandatory checks.

**Do not implement Task 4.**

---

# Task 4 — Conversational agent integration

## Goal

Connect the Task 1 agent foundation, Task 2 runtime context and availability tool, and Task 3 coordinator into a coherent conversational read path.

## Implementation

### Agent execution

For each authorized AI turn:

1. Resolve the trusted Business and capture a consistent operation time.
2. Build fresh descriptive Business context from Task 2.
3. Construct the agent's instructions from stable behavioral rules and clearly delimited runtime data.
4. Load completed earlier turns from S10 through Task 3's turn associations.
5. Supply historical messages using the SDK mechanism verified in Task 1.
6. Exclude the current inbound message and all later turns from that history.
7. Register only `CheckAvailabilityTool`.
8. Execute the agent with the current inbound text.
9. Submit the result through Task 3's authorized completion path.

The operation time must be available to the agent in the Business timezone so relative dates can be interpreted consistently.

Runtime Business context is regenerated for each execution and is not persisted as semantic Conversation state.

Use the installed SDK's supported agent configuration and dependency-injection APIs.

### Agent behavior

Configure the agent to:

- Answer descriptive enquiries from runtime Business context.
- Interpret colloquial Service and Professional references.
- Select canonical IDs when invoking availability.
- Ask for clarification when several Services or Professionals are plausible.
- Preserve explicit Professional preferences.
- Interpret relative dates using the Business timezone and operation time.
- Recognize changed preferences and topic changes.
- Avoid asking again for sufficiently established information.
- Use `CheckAvailabilityTool` whenever actual availability must be determined.
- Never infer valid Appointment starts from opening hours alone.
- Avoid inventing unavailable or unconfigured facts.

Do not hardcode reference-Business values in stable instructions.

### Context continuity

Use completed-turn history rather than raw insertion order when requests overlap.

Do not assume transient SDK tool results persist between turns.

When previously discussed configuration changes, use current authoritative values. Clarify stale or ambiguous references when necessary.

Do not expose canonical IDs as customer-facing labels unless they are needed to resolve an ambiguity appropriately.

### Errors

Reuse Task 3's failure/recovery behavior.

Distinguish ordinary clarification and unavailable information from technical failures.

Do not persist fabricated success after provider failure.

## Acceptance criteria

- One agent receives fresh runtime Business context and the correct S10 history.
- Descriptive data is available without descriptive lookup tools.
- Only the availability read tool is registered.
- Availability requests use S03.
- Current/later messages do not leak into historical context.
- Changed preferences and clarification are supported.
- Business isolation is maintained.
- Assistant persistence is controlled by the coordinator.
- No SDK-native Conversation storage or write tools are introduced.

## Tests

Use SDK fakes and application tests to verify:

1. The correct Business context reaches the agent.
2. Context is rebuilt between executions.
3. Business and Conversation isolation.
4. Only the approved tool is registered.
5. Availability tool wiring and structured results.
6. Correct history reconstruction and current-message exclusion.
7. Conversation expiration and replacement.
8. Assistant persistence and provider failure handling.

Test the context/tool integration deterministically. Actual model decisions about when to invoke a tool belong to Task 5's live evaluation.

Run the complete backend validation.

**Do not implement Task 5.**

---

# Task 5 — Conversational acceptance and regression

## Goal

Validate S11 against FR-01, FR-02, FR-03, and FR-08 through realistic multi-turn scenarios.

## Implementation

### Automated verification

Exercise the complete path:

S10 inbound persistence → AI-turn coordination → runtime Business context → SDK agent → optional availability tool → authoritative Laravel capabilities → assistant persistence.

Cover:

1. Business enquiries, including unavailable configuration.
2. Services, prices, durations, and eligible Professionals.
3. Canonical ID preservation and duplicate Professional names.
4. Availability with explicit or unspecified Professional preferences.
5. Unavailable/ineligible Professionals and alternatives.
6. Context-dependent references and clarification.
7. Changes of Service, Professional, date, or topic.
8. Conversation inactivity and current-context separation.
9. Duplicate/concurrent messages and recoverable failures.
10. Business isolation and read-only behavior.
11. No SDK-native Conversation persistence.
12. S01–S03 and S10 regressions.

Use SDK fakes for provider integration tests.

Do not treat fake responses as evidence that the model correctly interprets natural language.

### Manual OpenAI verification — [Developer]

Using the configured model and Lúmina reference data, verify:

- Colloquial and alternative Service names.
- Ambiguous Services and duplicate Professional names.
- Descriptive enquiries answered without lookup-tool calls.
- Actual availability enquiries invoking S03.
- Relative dates and Business-local time.
- Exact-start requests.
- References such as "the second Service".
- Explicit Professional preferences and available alternatives.
- Changed preferences and topic changes.
- Multi-turn continuity without unnecessary repetition.
- Business configuration changes reflected in later turns.
- No fabricated availability based on descriptive context.

Record pass/fail results and concrete defects.

If a required scenario fails because text-only history loses essential tool context, document the failure and obtain Developer approval before adding persistent tool transcripts.

Do not commit provider credentials or sensitive interaction transcripts.

### Regression and documentation

Run the complete backend suite, required lint/static checks, relevant PostgreSQL concurrency tests, and existing S01–S03/S10 regressions.

Verify that the implemented AI/context boundary matches `docs/product.md`, `docs/architecture.md`, and this S11 plan.

Apply only evidence-backed documentation corrections if implementation reveals a mismatch.

## Acceptance criteria

- FR-01 and FR-02 enquiries are answered from authoritative runtime context.
- FR-03 availability is obtained through S03.
- FR-08 continuity, contextual interpretation, and clarification meet the agreed scenarios.
- Business timezone and Professional preferences are respected.
- Missing information and valid Appointment availability are not fabricated.
- AI turns are ordered, idempotent, and recoverable.
- No S12 operations or unrelated infrastructure are introduced.
- Required automated checks pass.
- Manual provider verification is completed or explicitly recorded as unverified.

---

# Slice acceptance

S11 is complete when:

- Laravel AI SDK consumes S10 history without a separate persistent Conversation store.
- Runtime Business context provides authoritative customer-facing configuration.
- The agent exposes only the necessary read-only availability tool.
- Scheduling and Professional eligibility remain authoritative in Laravel.
- Natural-language interpretation and multi-turn continuity satisfy the acceptance scenarios.
- Turn coordination, idempotency, and recovery are verified.
- Documentation accurately reflects the implemented architecture.
- Required tests and Developer validation are completed or explicitly documented.

The Developer reviews and accepts S11 before marking it `Done` in `v1.md`.

The Agent must not stage or commit changes.

---

## References

Consult only applicable sections of:

- `docs/product.md` — FR-01, FR-02, FR-03, FR-08, and Section 6.
- `docs/plans/v1/v1.md` — S11.
- `docs/plans/v1/slices/S10-conversation-foundation.md`.
- `docs/architecture.md` — AI/application boundaries.
- `docs/adr/0004-laravel-ai-sdk.md`.
- Existing S01–S03 capabilities and DTO contracts.
- Official Laravel AI SDK documentation and installed package source.

Follow `AGENTS.md` and applicable backend rules. Do not redesign unrelated slices.
