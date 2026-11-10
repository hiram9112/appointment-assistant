# S10 — Conversation foundation

**Status:** Active  
**Depends on:** Milestone 2  
**Covers:** FR-08; BR-07

## Goal

Provide persistent, channel-independent Conversation handling for coherent multi-turn interactions.

Conversations must preserve message history, support continuity within a configurable inactivity period, and remain independent of Customer and Appointment persistence.

Conversational context is reconstructed from messages belonging to the current Conversation. No separately persisted semantic state is required for V1.

---

## Design decisions

### Conversation identity and Customer association

A Conversation belongs to one Business and is identified through:

- Business;
- channel;
- stable sender/session identity supplied by a trusted channel integration.

A Conversation may exist without a registered Customer.

Once a Customer is resolved or registered through the existing S04 capabilities, the current Conversation may be associated with that Customer without restarting the interaction or losing messages.

Conversation identity and Customer identity remain separate. Multiple Conversations may belong to the same participant over time.

Do not infer Customer identity from user-provided names or phone numbers.

### Persistence

Use two PostgreSQL tables.

**`conversations`**

Store:

- Business reference;
- optional Customer reference;
- channel;
- sender/session identity;
- last activity timestamp;
- standard persistence timestamps.

**`conversation_messages`**

Store:

- Conversation reference;
- role (`user` or `assistant`);
- message content;
- nullable idempotency key;
- standard persistence timestamps.

Each message is an independent record associated with one Conversation.

Retrieve history using chronological ordering, with message ID as a deterministic tie-breaker.

Do not introduce an `active_state` JSONB column, a separate state table or stored semantic summaries.

### Context and inactivity

The current Conversation's persisted message history provides context for later AI integration.

S10 does not interpret natural language, resolve semantic ambiguity or extract structured booking preferences.

Use a configurable inactivity threshold with an initial value of **12 hours**.

When a new inbound message arrives:

- reuse the latest Conversation for the participant if it remains within the threshold;
- otherwise create a new Conversation;
- preserve previous Conversations and their messages.

At the exact inactivity boundary, the previous Conversation is expired.

The threshold is measured from `last_activity_at` and checked on message arrival. No scheduled expiration process is required.

New Conversations do not inherit the previous Conversation's message context. Existing Customers and Appointments remain unaffected.

### Message idempotency

A repeated delivery of the same inbound message must not create another message or Conversation.

Require a stable message identifier from the trusted inbound integration. Construct an idempotency key from the normalized combination of Business, channel, sender/session identity and inbound message identifier.

Store that key in `conversation_messages` with a database-level uniqueness constraint.

The uniqueness scope must remain valid across separate Conversations. It must not depend on `conversation_id`.

Assistant messages may have a null idempotency key.

Identical text in different messages is permitted. Reusing an idempotency key with conflicting message content must be rejected.

Duplicate deliveries must not renew Conversation activity, including when the original Conversation has expired.

### Concurrency

Concurrent messages from the same participant must not create competing active Conversations.

Use a PostgreSQL transaction with participant-scoped synchronization.

Because a Conversation may not exist yet, row locking on `conversations` alone is insufficient. Use a deterministic PostgreSQL transaction-level advisory lock, scoped to the Business, channel and sender/session identity.

Within the lock, recheck message idempotency before resolving or creating the Conversation.

Preserve both distinct messages when they arrive concurrently.

S10 guarantees consistent persistence and deterministic retrieval, not semantic interpretation or ordering of future AI responses.

### Integration boundaries

Provide reusable Laravel application capabilities for Conversation resolution, message recording and history retrieval.

Do not implement OpenAI calls, conversational tools, WhatsApp transport, web demo endpoints or Business Admin functionality.

Future interfaces will authenticate their callers and supply normalized channel identity information.

The future Business Admin may retrieve Business-scoped Conversation history. Its access control, endpoints and UI remain outside S10.

---

## Scope boundaries

S10 excludes:

- AI interpretation, tool calling and clarification;
- structured conversational memory;
- WhatsApp, n8n and provider integration;
- public web chat and Business Admin UI;
- new Customer registration or Appointment operations;
- automatic Conversation cleanup;
- queues, Redis locks or generalized messaging infrastructure.

---

# Task 1 — Conversation persistence

## Goal

Introduce the persistent Conversation and ConversationMessage models, their database constraints, and the agreed documentation changes.

## Implementation

### Documentation alignment

Update:

- `docs/product.md` — FR-08;
- `docs/plans/v1/v1.md` — S10 acceptance direction.

Clarify that:

- Conversation history is persisted through individual message records;
- current context is reconstructed from the active Conversation's history;
- no separate structured semantic state is required;
- changed preferences, ambiguity and relevant-context interpretation remain required behaviors, implemented in the AI slices;
- previous Conversations remain stored but are not automatically treated as current intent.

Preserve the existing FR-08 requirements for coherent multi-turn interaction, continuity, clarification and inactivity behavior.

### Database and models

Create migrations for `conversations` and `conversation_messages`.

Introduce the corresponding Eloquent models and relationships:

- Business has many Conversations;
- Customer may have many Conversations;
- Conversation belongs to Business;
- Conversation optionally belongs to Customer;
- Conversation has many ConversationMessages;
- ConversationMessage belongs to Conversation.

Add appropriate foreign keys, lookup indexes and a globally unique nullable message idempotency key.

The intended participant lookup uses Business, channel, sender/session identity and recent activity.

Ensure message history can be retrieved efficiently and deterministically for a Conversation.

Use established Laravel and PostgreSQL conventions.

Do not introduce additional tables or application-level message processing in this task.

## Acceptance criteria

- FR-08 and the S10 roadmap reflect the accepted history-based context design.
- Conversations belong to a Business and may have no Customer.
- A Conversation may subsequently reference a registered Customer.
- Multiple Conversations may exist for the same participant.
- Messages persist individually and reference a valid Conversation.
- Both supported message roles can be persisted.
- Relational integrity is enforced.
- Idempotency uniqueness is independent of Conversation boundaries.
- History remains persisted without an active-state representation.

## Tests

- Model relationships and nullable Customer association.
- Multiple Conversations for one participant.
- Multiple messages belonging to one Conversation.
- Foreign-key integrity.
- Message role and content persistence.
- Unique non-null idempotency keys, including across Conversations.
- Multiple null idempotency keys for assistant messages.
- Stable message ordering for equal timestamps.

Run relevant database tests and formatting checks.

---

# Task 2 — Conversation lifecycle and message handling

## Goal

Implement Laravel capabilities for resolving Conversations, recording messages and retrieving history with reliable inactivity, idempotency and concurrency behavior.

## Implementation

### Inbound message handling

Accept normalized Business, channel, sender/session identity, inbound message identifier and content.

Within a PostgreSQL transaction:

1. Derive the scoped idempotency key.
2. Return an existing matching message if it was already recorded.
3. Acquire the transaction-level advisory lock for the participant.
4. Recheck idempotency under the lock.
5. Retrieve the latest Conversation for the participant.
6. Reuse it or create a new Conversation according to the inactivity threshold.
7. Insert the incoming `user` message.
8. Update Conversation activity and commit.

Reject conflicting reuse of an idempotency key.

Return structured application results consistent with existing project conventions.

### Assistant messages and history

Support recording an `assistant` message in an existing Business-scoped Conversation without invoking an AI provider.

Update Conversation activity when a new message is successfully recorded.

Provide ordered history retrieval for a specified Conversation.

Validate the requested Conversation's Business association. Do not expose another Business's Conversation data.

### Customer association

Support associating an existing Conversation with a Customer resolved through S04.

Preserve existing messages and Conversation identity.

Do not create or modify Customer records as part of Conversation persistence.

### Failure handling

Expected validation failures must be distinguishable from technical persistence failures.

A failed message insertion must not leave a new Conversation or updated activity timestamp committed.

Do not add a new transaction framework or external coordination service.

## Acceptance criteria

- The first inbound message creates a Conversation.
- Subsequent messages within 12 hours reuse it.
- Messages arriving at or beyond the inactivity boundary begin a new Conversation.
- The inactivity threshold is configurable without schema changes.
- Expired Conversations and their messages remain available.
- A duplicate delivery does not create records or update activity.
- A duplicate received after expiration does not create a new Conversation.
- Conflicting reuse of an idempotency key is rejected.
- Distinct messages with identical content are preserved.
- Concurrent first messages create only one active Conversation.
- Concurrent distinct messages are both persisted.
- Existing Conversations can be associated with a resolved Customer without losing history.
- Customer and Appointment persistence is unaffected by Conversation expiration.
- History retrieval is chronological, deterministic and Business-scoped.
- No AI interpretation or provider integration is introduced.

## Tests

### Lifecycle

- First message and subsequent messages.
- Inactivity immediately before, exactly at and after the threshold.
- Configurable threshold.
- Historical Conversation preservation.
- Customer association after several messages.

### Idempotency

- Repeated delivery of the same message.
- Repeated delivery after Conversation expiration.
- Identical content with distinct inbound identifiers.
- Conflicting content with the same idempotency key.
- Duplicate delivery without activity renewal.

### Concurrency and persistence

- Simultaneous first messages for the same participant.
- Concurrent distinct messages within an existing Conversation.
- Concurrent duplicate deliveries.
- Transaction rollback on message persistence failure.
- Business and participant isolation.

### History and regression

- User and assistant message recording.
- Deterministic chronological retrieval.
- Conversation history isolation between Businesses.
- Existing Customer identity behavior remains valid.

Reuse existing PostgreSQL concurrency testing infrastructure.

Run the complete backend test suite and mandatory lint checks.

---

# Slice acceptance

S10 is complete when:

- Conversations and messages persist correctly;
- Conversation history supports context reconstruction without structured semantic state;
- inactivity, idempotency and concurrency behavior are validated;
- optional Customer association preserves existing history;
- FR-08 and the V1 roadmap match the accepted implementation;
- applicable backend tests and checks pass.

The Developer reviews and accepts the implementation before marking S10 as `Done`.

Do not create a Git commit. The Developer controls repository history.
