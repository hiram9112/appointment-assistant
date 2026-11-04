# S04 — Customer registration and identity

**Status:** Active  
**Depends on:** Milestone 1  
**Covers:** FR-14; BR-07

## Goal

Establish persistent Customer registration and identity resolution through deterministic Laravel application capabilities.

Customer identity must remain independent from Conversations, Appointments, AI and external messaging providers.

A Contact may request Business information, Services and Appointment availability without registration. Registration is required before creating a real Business Appointment.

---

## Design decisions

### Customer persistence

`Customer` is a persistent application entity.

Expected `customers` data:

- `id`;
- `first_name`;
- `last_name`;
- `phone`, nullable;
- Laravel timestamps.

`first_name` and `last_name` are required.

`last_name` may contain one or multiple surnames, including compound surnames.

Names must support spaces, accents and other legitimate name characters.

Names are not unique identifiers. Different Customers may share the same full name.

`phone` is optional contact data. When provided, its format must be validated without requiring ownership verification.

Phone numbers are not unique identifiers and must not be used to establish or recover Customer identity or authorize access to Appointments.

### Channel identity persistence

Introduce a persistent entity conceptually named `ChannelIdentity`, separate from `Customer`.

Expected `channel_identities` data:

- `id`;
- `customer_id`;
- `channel`;
- `external_id`;
- Laravel timestamps.

A ChannelIdentity belongs to one Customer. A Customer may have multiple associated channel identities.

`customer_id` references the internal Customer identity.

`channel` identifies the interaction channel.

`external_id` is the persistent identifier supplied by the external channel through a trusted integration.

External identifiers are opaque values. The application must not derive them from names or phone numbers, interpret their provider-specific structure or alter their values.

The pair (`channel`, `external_id`) must be unique in PostgreSQL.

S04 supports only `whatsapp`. Channel persistence must remain structurally extensible without implementing other channels.

V1 contains one Business. Do not introduce multi-Business identity scope or SaaS infrastructure.

Different external identities must not be automatically merged or linked based on matching personal information.

### Trusted identity boundary

Customer resolution and registration require channel identity values supplied by a trusted application caller.

Names, phone numbers or identifiers declared within message content must never be used as authentication evidence.

The LLM must not establish or override Customer identity.

S04 defines internal application capabilities and their input contracts. It does not authenticate external providers.

Verification of WhatsApp communications and extraction of trusted sender identity belong to S13.

No HTTP endpoints are introduced in S04.

### Registration timing

A Contact is not registered merely by asking questions or checking availability.

Registration is initiated when the Contact proceeds toward booking a real Appointment.

The Customer must be registered before the Appointment is persisted.

S04 does not implement booking, registration conversation flows or Appointment ownership enforcement. Those responsibilities belong to later slices.

### Customer resolution

Introduce an internal Laravel application capability conceptually named:

`ResolveCustomer`

Inputs:

- `channel`;
- `external_id`.

Behavior:

- validate the supported channel and required external identifier;
- find the matching ChannelIdentity;
- return its associated Customer when found;
- return `null` when no matching identity exists.

Resolution is read-only.

It must not search by Customer name or phone, create Customers, modify existing records or merge identities.

The same registered channel identity must resolve consistently to the same Customer across separate Conversations.

### Customer registration

Introduce an internal Laravel application capability conceptually named:

`RegisterCustomer`

Inputs:

- `first_name`;
- `last_name`;
- optional `phone`;
- trusted `channel`;
- trusted `external_id`.

The normal registration flow follows a previous `ResolveCustomer` result indicating that the identity is not registered.

Registration must nevertheless defensively check whether the identity already exists.

When the identity exists:

- return an explicit `AlreadyRegistered` outcome;
- do not create a Customer;
- do not modify existing Customer information.

When the identity does not exist:

- validate the registration data;
- create the Customer and associated ChannelIdentity atomically;
- return the newly registered Customer.

Normalize unnecessary whitespace in Customer name fields without restricting legitimate names. Do not normalize or transform opaque external identifiers.

`RegisterCustomer` does not replace `ResolveCustomer` as the mechanism for retrieving registered Customers.

### Registration integrity and concurrency

Customer and ChannelIdentity creation must occur within one database transaction.

The unique constraint on (`channel`, `external_id`) protects against duplicate identities, including concurrent registration attempts.

A preliminary existence check alone is insufficient for concurrency protection.

If another request registers the same identity concurrently:

- the competing transaction must not leave an additional Customer;
- the registration attempt must return the appropriate `AlreadyRegistered` outcome;
- existing Customer information must remain unchanged.

Only an identified duplicate-identity conflict may be handled as `AlreadyRegistered`. Unrelated persistence errors must remain technical failures.

Failed registration must not leave orphan Customers or partially persisted identities.

### Application conventions

Follow the Laravel application-layer conventions established in S01–S03 where applicable.

Eloquent may be used directly. Do not introduce repository abstractions or additional identity infrastructure without a concrete need.

Application results must distinguish successful registration, already registered identity, invalid input and technical failure.

---

## Scope boundaries

S04 does not implement:

- Appointment creation, persistence or ownership enforcement;
- Appointment retrieval or modification;
- Conversation persistence or conversational registration flows;
- AI tools, prompts or orchestration;
- HTTP/API endpoints;
- WhatsApp transport, webhooks or provider authentication;
- n8n integration;
- phone ownership verification or OTP;
- Customer profile editing;
- Customer identity recovery, merging or cross-channel linking;
- additional communication channels;
- public demo identity management;
- administration UI;
- multi-Business or SaaS identity infrastructure.

---

# Task 1 — Customer persistence

## Goal

Introduce the persistent Customer entity and its registration data.

## Implementation

Implement:

- `customers` migration;
- `Customer` Eloquent model;
- required model configuration;
- factory for automated tests;
- relevant persistence tests.

Persist `first_name`, `last_name` and nullable `phone`.

Follow existing Laravel persistence conventions.

Input validation belongs to the registration capability in Task 4.

Do not implement ChannelIdentity persistence or application capabilities yet.

## Acceptance criteria

- Customers can be persisted and retrieved;
- first name and surname are non-nullable persistent fields;
- `last_name` can store one or multiple surnames;
- names support legitimate international characters;
- phone is nullable;
- names and phone numbers have no uniqueness constraints;
- distinct Customers remain identifiable by their internal IDs.

## Tests

Cover at least:

1. complete Customer persistence;
2. required persistent fields;
3. single and multiple surnames;
4. names containing spaces and accented characters;
5. nullable phone;
6. identical full names across different Customers;
7. identical phone numbers across different Customers.

Do not implement Task 2 as part of this task.

---

# Task 2 — Channel identity persistence

## Goal

Persist external channel identities separately from Customers with referential integrity and uniqueness.

## Implementation

Implement:

- `channel_identities` migration;
- `ChannelIdentity` Eloquent model;
- Customer ↔ ChannelIdentity relationships;
- foreign-key integrity;
- unique (`channel`, `external_id`) constraint;
- relevant persistence tests.

Use a generic channel field rather than provider-specific database structures.

A Customer may have multiple ChannelIdentities, while each ChannelIdentity references exactly one Customer.

Supported-channel validation belongs to the application capabilities in Tasks 3 and 4.

Do not implement provider integration or application capabilities in this task.

## Acceptance criteria

- ChannelIdentity records persist independently from Customer information;
- every ChannelIdentity references an existing Customer;
- both Eloquent relationship directions work;
- `channel` and `external_id` are non-nullable;
- duplicate (`channel`, `external_id`) combinations are prevented;
- external identifiers are stored without interpretation or transformation;
- foreign-key integrity prevents orphan identities.

## Tests

Cover at least:

1. ChannelIdentity persistence;
2. Customer-to-identities relationship;
3. identity-to-Customer relationship;
4. multiple identities associated with one Customer;
5. required persistent fields;
6. duplicate identity constraint;
7. invalid Customer reference;
8. opaque external identifier persistence.

Do not implement Task 3 as part of this task.

---

# Task 3 — Customer identity resolution

## Goal

Expose a deterministic Laravel application capability for resolving registered Customers through trusted channel identity values.

## Implementation

Introduce:

`ResolveCustomer`

The capability receives:

- `channel`;
- `external_id`.

It validates the input and queries authoritative ChannelIdentity persistence.

Return the associated Customer when found, or `null` for an unknown identity.

Only `whatsapp` is supported in S04.

Do not use names, phone numbers or conversational data to resolve identity.

Follow existing application capability conventions.

Do not introduce HTTP interfaces, messaging integrations or registration behavior.

## Acceptance criteria

- a registered identity resolves the correct Customer;
- an unknown identity returns `null`;
- invalid input and unsupported channels fail safely;
- resolution depends exclusively on channel identity;
- Customers with identical names or phone numbers remain distinguishable;
- repeated resolution returns the same persistent Customer;
- resolution does not modify persistence;
- no Conversation or Appointment record is required.

## Tests

Cover at least:

1. existing Customer resolution;
2. unknown identity;
3. missing or invalid identity input;
4. unsupported channel;
5. different identities with matching Customer names or phones;
6. repeated resolution;
7. no persistence changes;
8. resolution without Conversations or Appointments.

Do not implement Task 4 as part of this task.

---

# Task 4 — Customer registration capability

## Goal

Expose deterministic Customer registration with input validation, atomic persistence and duplicate-identity protection.

## Implementation

Introduce:

`RegisterCustomer`

Follow existing Laravel application capability conventions.

Validate:

- non-empty `first_name`;
- non-empty `last_name`;
- optional phone format;
- supported `channel`;
- non-empty `external_id`.

Apply reasonable field-length limits and normalize unnecessary whitespace in Customer names.

Accept legitimate international names and compound surnames.

Do not alter external identifiers.

Registration behavior:

1. validate the supplied input;
2. check defensively whether the channel identity already exists;
3. return `AlreadyRegistered` if it exists, without modifying the Customer;
4. otherwise create Customer and ChannelIdentity in one transaction;
5. return the newly registered Customer after successful persistence.

Handle concurrent registration attempts through database uniqueness enforcement and appropriate conflict handling.

An expected duplicate-identity conflict must result in `AlreadyRegistered`, after rolling back the unsuccessful registration.

Other persistence errors must not be concealed or incorrectly treated as duplicate registrations.

The exact result type and method signatures should follow existing application conventions while preserving the agreed behavior.

Do not implement HTTP endpoints, AI orchestration, WhatsApp integration or Appointment creation.

## Acceptance criteria

- valid registration creates one Customer and one ChannelIdentity;
- first name and at least one surname are required;
- registration succeeds without a phone;
- a valid optional phone is persisted;
- invalid registration data is rejected;
- only the supported channel is accepted;
- an existing identity produces `AlreadyRegistered`;
- repeated registration does not overwrite Customer data;
- matching names or phone numbers do not merge Customers;
- registration persists atomically;
- concurrent registration cannot produce duplicate Customers for one identity;
- failed registration leaves no partial records;
- unrelated database failures remain technical failures;
- a newly registered Customer can subsequently be retrieved through `ResolveCustomer`;
- registration does not depend on Conversations, Appointments or external providers.

## Tests

Cover at least:

1. successful registration and identity association;
2. required first name and surname;
3. name normalization and compound surnames;
4. registration with and without phone;
5. invalid phone format;
6. unsupported channel or invalid external ID;
7. already registered identity;
8. existing Customer information remains unchanged;
9. distinct identities sharing names or phone numbers;
10. atomic transaction rollback;
11. duplicate identity and concurrent registration conflict handling;
12. unrelated persistence failure handling;
13. successful registration followed by `ResolveCustomer`;
14. registration without Conversation or Appointment records.

---

# Slice acceptance

S04 is complete when:

- Customers are persisted with first name, surname and optional phone;
- ChannelIdentity persistence is separate and correctly related to Customers;
- channel identities are unique and external identifiers remain opaque;
- Customer resolution uses trusted channel identity values rather than declared personal information;
- `ResolveCustomer` retrieves existing Customers without modifying state;
- `RegisterCustomer` creates new Customers atomically and returns an explicit outcome for existing identities;
- concurrent registration cannot create duplicate Customers for one channel identity;
- Customer identity persists independently from Conversations and Appointments;
- the capabilities remain independent from AI, HTTP and external providers;
- all S04 tests and existing relevant backend validation pass.

After Task 4, run the complete backend validation required by the repository and review the implementation against FR-14, BR-07 and applicable architecture constraints.
