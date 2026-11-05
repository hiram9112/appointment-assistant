# S05 — Create Appointment

**Status:** Active  
**Depends on:** S03, S04  
**Covers:** FR-04; FR-11; BR-04; BR-05; BR-06; BR-07; BR-10; BR-13; BR-14

## Goal

Create confirmed Appointments for registered Customers using current authoritative scheduling state.

Appointment creation must revalidate availability, prevent overlapping Professional occupancy under concurrent requests, persist authoritative state and produce an audit record.

The capability must remain independent from AI, messaging channels and external integrations.

---

## Design decisions

### Appointment persistence

`Appointment` is a persistent application entity.

Expected `appointments` data:

- `id`;
- `customer_id`;
- `service_id`;
- `professional_id`;
- `starts_at`;
- `ends_at`;
- `buffer_before_minutes`;
- `buffer_after_minutes`;
- `status`;
- Laravel timestamps.

Each Appointment belongs to one registered Customer, one Service and one Professional.

The `Customer`, `Service` and `Professional` references must maintain foreign-key integrity.

`Service` and `Professional` must belong to the applicable Business. Professional-Service eligibility is validated at the application boundary.

Do not duplicate `business_id` on Appointment or introduce multi-Business infrastructure.

### Appointment timing

`starts_at` and `ends_at` represent the Customer-facing Service interval.

The complete Professional occupancy is:

```text
occupancy_start = starts_at - buffer_before_minutes
occupancy_end   = ends_at + buffer_after_minutes
```

`ends_at` is calculated from `starts_at` and the Service's current `duration_minutes`.

Buffers are copied from the Service's current scheduling configuration when the Appointment is created.

Persist the effective start, end and buffer values. Later changes to Service duration or buffers must not retroactively change existing Appointments.

Do not persist a separate total occupancy duration.

Store Appointment instants using PostgreSQL timezone-aware timestamps, normalized to UTC. Use the Business's configured timezone for Business-local scheduling interpretation.

All occupancy intervals use half-open boundaries: `[start, end)`.

Two occupancy intervals that touch at their endpoints do not overlap.

### Appointment lifecycle

The Appointment model supports these states:

- `confirmed`;
- `cancelled`;
- `completed`.

Every successfully created Appointment starts as `confirmed`.

Only `confirmed` Appointments consume scheduling availability.

Cancelled and completed Appointments remain persistent historical records.

State transitions belong to later slices. S05 does not implement cancellation, rescheduling or completion operations.

### Future-time validation

Appointment creation requires `starts_at` to be strictly later than the current instant.

Same-day booking is allowed when the requested start time is still in the future.

Past or current start times must be rejected, regardless of apparent schedule availability.

Use a testable application clock or established Laravel time-testing conventions.

The S03 availability capability must not present past Appointment start times as bookable.

When connecting S03 to persisted occupancy, verify whether this protection already exists and add only the missing behavior and tests.

Do not introduce a configurable minimum booking lead time without an applicable Business rule.

### PostgreSQL concurrency protection

Use two complementary protections:

1. Laravel revalidates current availability through the S03 scheduling logic.
2. PostgreSQL enforces a database-level exclusion constraint preventing overlapping confirmed Appointment occupancy for the same Professional.

The database constraint must account for both scheduling buffers and use half-open occupancy ranges.

A suitable PostgreSQL GiST range/exclusion mechanism must enforce the invariant atomically. Use the required PostgreSQL extension or compatible immutable range representation where necessary.

The constraint applies only to `confirmed` Appointments.

Different Professionals may have overlapping Appointments.

A previously offered slot does not reserve scheduling capacity.

When two concurrent requests attempt overlapping bookings for the same Professional, at most one may succeed.

An identified PostgreSQL occupancy exclusion conflict must produce a `SlotUnavailable` application outcome rather than an unrelated technical failure.

Do not treat arbitrary database errors as scheduling conflicts.

Do not introduce Redis locks, distributed locks or a generalized reservation framework.

### Authoritative occupancy integration

S03 already provides the scheduling calculation and an abstraction for occupied Professional intervals.

S05 introduces the PostgreSQL-backed occupancy implementation using persisted Appointments.

The implementation must:

- retrieve only `confirmed` Appointments;
- restrict occupancy to the relevant Professional;
- use the Appointment's persisted timing and buffer values;
- include occupancy that intersects the requested period, even when it starts before that period;
- preserve half-open interval semantics;
- provide the occupied intervals expected by the existing S03 calculation.

Do not replace or duplicate the S03 scheduling algorithm.

Preserve its existing controlled/fake occupancy testing capability.

The real occupancy implementation must be used by normal application availability queries and Appointment creation.

### Appointment audit

Introduce a persistent append-only audit mechanism, conceptually named `AppointmentAudit`.

Each audit record preserves:

- affected `appointment_id`;
- `action`;
- responsible `actor` or system component;
- interaction `source`;
- `before_state`;
- `after_state`;
- operation timestamp.

`actor` answers who or what performed the operation.

`source` identifies the originating interface or application source.

They are separate concepts.

The audit mechanism must represent an actor's meaningful type and identifier without requiring an administrative user system in S05.

Audit context comes from a trusted application caller, not from untrusted conversational content.

For creation:

- `action` is `created`;
- `before_state` is `null`;
- `after_state` captures the relevant persisted Appointment state.

The state snapshot must preserve enough information to reconstruct the Appointment change independently from subsequent modifications to the Appointment or its related configuration.

Audit records are append-only through normal application operations.

Future Appointment-changing capabilities must be able to reuse the same mechanism.

S05 implements only the `created` audit action.

Appointment creation and its audit insertion occur within the same PostgreSQL transaction.

If either persistence operation fails, neither operation may be committed.

### CreateAppointment capability

Introduce a reusable internal Laravel application capability conceptually named:

`CreateAppointment`

Inputs:

- applicable `Business`;
- registered `Customer`;
- requested `Service`;
- selected `Professional`;
- requested `starts_at`;
- trusted audit context containing `actor` and `source`.

The requested Professional is mandatory.

The caller does not supply authoritative Service duration, buffer values or `ends_at`.

The capability derives those values from current authoritative Service configuration.

It validates:

- registered Customer existence;
- Service existence, active state and Business association;
- Professional existence and Business association;
- Professional-Service eligibility;
- future Appointment start time;
- complete Appointment occupancy validity through S03;
- currently available scheduling capacity.

The capability must revalidate scheduling availability immediately before persistence.

Successful creation atomically persists:

- the confirmed Appointment;
- its corresponding audit record.

The result distinguishes:

- successful creation, returning the confirmed Appointment;
- invalid request or references;
- unavailable scheduling slot;
- technical failure.

A PostgreSQL exclusion conflict caused by competing Appointment creation maps to the unavailable-slot outcome.

The capability must not silently substitute another Professional, automatically select a Professional or choose a different time.

Available alternatives may be obtained through the existing S03 capabilities by later callers.

Return structured application results rather than Customer-facing conversational prose.

### External side effects

PostgreSQL remains authoritative for successful Appointment creation.

Notifications, reminders and Google Calendar synchronization belong to their respective later slices.

Do not add speculative provider calls, placeholder jobs or integration workflows.

When these integrations are introduced, they must operate from the authoritative Appointment state without changing the scheduling and creation invariants established here.

---

## Scope boundaries

S05 does not implement:

- Customer registration or identity recovery;
- Customer Appointment listing or retrieval capabilities;
- Appointment rescheduling;
- Appointment cancellation;
- Appointment completion transitions;
- automatic Professional assignment;
- conversational clarification or confirmation flows;
- AI tools or prompts;
- HTTP/API endpoints;
- WhatsApp or n8n integration;
- Professional notifications;
- Customer reminders;
- Google Calendar synchronization;
- administration UI;
- public demo identity or isolation infrastructure;
- multi-Business or SaaS infrastructure;
- configurable scheduling optimization;
- temporary Appointment reservations or holds.

---

# Task 1 — Appointment persistence and overlap integrity

## Goal

Introduce authoritative Appointment persistence and enforce the Professional occupancy invariant at the PostgreSQL level.

## Implementation

Implement:

- `appointments` migration;
- `Appointment` Eloquent model;
- Customer, Service and Professional relationships;
- required model configuration and casts;
- appropriate factory for tests;
- PostgreSQL overlap exclusion constraint;
- persistence and database-integrity tests.

Persist the agreed Appointment fields and support the three lifecycle statuses.

Use timezone-aware PostgreSQL timestamps for Appointment instants.

Require positive Service time intervals and non-negative persisted buffer values.

Configure PostgreSQL so that overlapping confirmed occupancy for the same Professional is prohibited, including overlap caused exclusively by buffers.

Do not implement scheduling calculation or creation capabilities in this task.

## Acceptance criteria

- Appointments persist with valid Customer, Service and Professional references;
- Appointment timestamps and buffers are persisted;
- valid UTC instants are retained correctly;
- status is persisted and restricted to supported lifecycle values;
- the model supports `confirmed`, `cancelled` and `completed`;
- buffers cannot be negative;
- `ends_at` must be later than `starts_at`;
- invalid foreign keys are rejected;
- confirmed occupancy cannot overlap for the same Professional;
- buffer-only conflicts are also prevented;
- exactly adjacent occupancy intervals are permitted;
- different Professionals may have overlapping Appointments;
- non-confirmed Appointments do not participate in the exclusion constraint;
- no additional Appointment lifecycle behavior is introduced.

## Tests

Cover at least:

1. complete Appointment persistence and relationships;
2. required foreign keys;
3. UTC timestamp persistence and retrieval;
4. stored buffer values;
5. invalid status;
6. invalid or zero-length Appointment interval;
7. negative buffers;
8. overlapping confirmed Appointments;
9. overlap caused by before and after buffers;
10. exactly adjacent occupancy;
11. overlapping times for different Professionals;
12. cancelled and completed records excluded from active occupancy protection.

Do not implement Task 2 as part of this task.

---

# Task 2 — PostgreSQL occupancy integration

## Goal

Connect persisted active Appointments to the existing S03 availability calculation.

## Implementation

Introduce the PostgreSQL-backed implementation of the existing S03 occupied-interval boundary.

Use persisted confirmed Appointments as the authoritative occupancy source.

Derive occupied intervals from stored start, end and buffer values.

Preserve the existing S03 calculation and application capability conventions.

Verify the previously identified future-time rule:

- availability queries must not expose past Appointment starts as bookable;
- bookings later today remain possible when their start is in the future.

Implement only the missing adjustment, if any.

Use existing Laravel time-testing facilities for deterministic time-dependent tests.

Do not introduce new Appointment creation or lifecycle behavior in this task.

## Acceptance criteria

- S03 availability uses persisted confirmed Appointment occupancy;
- occupancy is restricted to the relevant Professional;
- persisted buffers affect availability;
- existing Appointments remain correctly represented after Service configuration changes;
- cancelled and completed Appointments do not block availability;
- an interval starting before the requested period is included when it overlaps;
- adjacent intervals do not produce false conflicts;
- queries do not expose past starts as bookable;
- same-day future availability remains available when otherwise valid;
- the existing S03 scheduling algorithm and its controlled test boundaries remain usable.

## Tests

Cover at least:

1. persisted confirmed Appointment removes availability;
2. multiple confirmed Appointments;
3. Professional-specific occupancy;
4. stored before buffer;
5. stored after buffer;
6. Service configuration changes do not alter existing occupancy;
7. cancelled Appointment excluded;
8. completed Appointment excluded;
9. occupancy crossing the query boundary;
10. adjacent non-overlapping intervals;
11. past-day availability request;
12. same-day past time;
13. exactly current time;
14. same-day future availability;
15. existing S03 availability tests remain green.

Do not implement Task 3 as part of this task.

---

# Task 3 — Appointment audit persistence

## Goal

Establish the persistent audit mechanism required for authoritative Appointment changes.

## Implementation

Implement:

- `appointment_audits` migration;
- `AppointmentAudit` model;
- Appointment ↔ audit relationships;
- structured before/after state storage using PostgreSQL JSONB;
- actor and source information;
- an application-owned mechanism for appending audit records;
- relevant persistence and behavior tests.

The audit design must retain actor identity independently from interaction source.

Support application-supplied trusted audit context without introducing administrative authentication or messaging infrastructure.

The shared mechanism must support later Appointment actions without implementing them now.

For creation, persist:

- `created` action;
- responsible actor;
- source;
- `null` before state;
- complete relevant after-state snapshot;
- action timestamp.

Preserve audit history. Do not provide ordinary application operations that update or delete audit records.

The transactional relationship between Appointment creation and audit insertion is exercised in Task 4.

## Acceptance criteria

- audit records reference existing Appointments;
- action and timestamp are persisted;
- actor and source are distinguishable;
- before and after state are structured data;
- creation audit permits a null before state;
- after state preserves relevant Appointment facts;
- audit records remain separate from mutable Appointment state;
- an Appointment may have multiple ordered audit records;
- normal application audit behavior appends rather than replacing history;
- the shared mechanism does not depend on AI, HTTP or provider integrations.

## Tests

Cover at least:

1. audit persistence;
2. Appointment relationship;
3. required audit information;
4. actor and source stored independently;
5. nullable before state;
6. structured after-state persistence;
7. multiple audit entries for one Appointment;
8. preserved historical snapshots;
9. no ordinary update/delete behavior in the audit-writing mechanism.

Do not implement Task 4 as part of this task.

---

# Task 4 — Create Appointment application capability

## Goal

Expose deterministic confirmed Appointment creation with authoritative revalidation, concurrency protection and transactional auditing.

## Implementation

Introduce the internal application capability:

`CreateAppointment`

Follow existing Laravel application-layer conventions.

The capability receives the applicable Business, registered Customer, Service, Professional, requested start instant and trusted audit context.

Validate required references and their Business relationships.

The Customer must be registered. Do not create or infer Customer identity inside `CreateAppointment`.

Validate active Service status and Professional-Service eligibility.

Use the current Service configuration to derive:

- Service end time;
- before buffer;
- after buffer;
- complete requested scheduling occupancy.

Require the requested start time to be strictly in the future.

Revalidate the requested start through the S03 availability calculation, using the authoritative PostgreSQL occupancy source from Task 2.

Do not implement a separate copy of the S03 scheduling rules.

Within the authoritative creation transaction:

- persist the confirmed Appointment;
- persist its creation audit record.

Ensure the transaction cannot commit an Appointment without its audit entry.

Handle an identified PostgreSQL occupancy exclusion conflict as `SlotUnavailable`.

Preserve other persistence errors as technical failures.

Return a structured successful result or an explicit failure outcome.

Do not automatically choose a replacement Professional or Appointment start time.

Do not add conversational response generation, external side effects or HTTP interfaces.

## Acceptance criteria

- a registered Customer can create an Appointment for an eligible Professional;
- an unregistered or nonexistent Customer cannot create a real Appointment;
- invalid or inactive Services are rejected;
- Services and Professionals from another Business are rejected;
- ineligible Professionals are rejected;
- the explicitly selected Professional is preserved;
- Appointment creation without a selected Professional fails;
- start times in the past or exactly at the current instant are rejected;
- valid future start times are supported;
- Service duration and buffers come from current authoritative configuration;
- final availability revalidation includes Business hours, Professional availability, exceptions, buffers and persisted occupancy;
- a previously available slot may fail safely when scheduling state changes;
- successful creation produces a confirmed Appointment;
- successful creation produces exactly one corresponding `created` audit record;
- Appointment and audit persistence are atomic;
- identified concurrent overlap conflicts return `SlotUnavailable`;
- unrelated persistence failures are not concealed;
- concurrent conflicting requests cannot both succeed;
- the capability returns structured scheduling facts;
- no automatic Professional substitution or scheduling optimization occurs;
- no AI or external integration is required.

## Tests

Cover at least:

1. successful confirmed Appointment creation;
2. correct Customer, Service and Professional association;
3. nonexistent or unregistered Customer;
4. invalid Service;
5. inactive Service;
6. other-Business Service or Professional;
7. ineligible Professional;
8. missing Professional selection;
9. past Appointment start;
10. Appointment start equal to current instant;
11. valid future Appointment start;
12. derived end time and persisted buffers;
13. Business operating-hours boundary;
14. Professional availability and recurring breaks;
15. Business and Professional scheduling exceptions;
16. existing confirmed Appointment occupancy;
17. previously available slot becoming unavailable;
18. adjacent non-overlapping Appointment;
19. explicit Professional selection preserved;
20. successful creation audit containing actor, source and after state;
21. rollback when audit persistence fails;
22. PostgreSQL occupancy exclusion conflict translated to `SlotUnavailable`;
23. unrelated persistence errors remain technical failures;
24. independent concurrent booking attempts cannot both commit conflicting confirmed Appointments;
25. successful creation without HTTP, AI or external providers;
26. Lúmina reference configuration exercising the complete creation capability.

---

# Slice acceptance

S05 is complete when:

- Appointments are authoritative PostgreSQL entities associated with registered Customers, Services and Professionals;
- confirmed Appointment timing and buffers are persisted independently from subsequent Service configuration changes;
- Appointment lifecycle states are represented, with only `confirmed` occupying scheduling capacity;
- PostgreSQL prevents overlapping confirmed Professional occupancy under concurrent writes;
- persisted confirmed Appointments supply the authoritative occupancy consumed by S03;
- past Appointment starts cannot be booked or presented as bookable;
- `CreateAppointment` validates current Service, Professional and scheduling rules without duplicating S03 logic;
- an explicitly selected Professional is never silently replaced;
- a successful creation atomically persists the confirmed Appointment and its audit record;
- audit history preserves action, timestamp, actor, source and relevant before/after state;
- scheduling conflicts produce a clear application-level unavailable-slot outcome;
- unrelated technical failures are not hidden;
- no later-slice Appointment lifecycle, conversational or external integration functionality is introduced;
- all S05 tests and existing relevant backend validation pass.

After Task 4, run the complete backend validation required by the repository and review the implementation against FR-04, FR-11, the applicable Business rules and architecture constraints.
