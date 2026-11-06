# S07 — Reschedule Appointment

**Status:** Active  
**Depends on:** S05, S06  
**Covers:** FR-06; FR-11; BR-04; BR-05; BR-06; BR-07; BR-08; BR-10; BR-13; BR-14

## Goal

Allow an identified, registered Customer to reschedule an existing confirmed Appointment to another valid slot while preserving Appointment identity, Customer ownership, the original Service and transactional consistency.

Reuse the existing scheduling, occupancy, PostgreSQL concurrency and Appointment audit mechanisms.

The capability must support explicitly selected Professional changes, apply current Service scheduling configuration, record late-notice incidents and leave the original Appointment unchanged whenever rescheduling fails.

---

## Design decisions

### RescheduleAppointment capability

Introduce the internal Laravel application capability:

`RescheduleAppointment`

Conceptual inputs:

- applicable `Business`;
- identified, registered `Customer`;
- existing `appointmentId`;
- requested `startsAt`;
- optional selected `Professional`;
- trusted audit context containing `actor` and `source`.

Follow existing Laravel application-layer conventions and structured result patterns.

The caller must not supply a replacement Service, authoritative `ends_at`, duration or scheduling buffers.

Resolve the existing Appointment from persistence and enforce Customer ownership.

The capability modifies the existing Appointment. It must not create a replacement Appointment or cancel the original.

### Appointment identity and Service

A successful rescheduling must preserve:

- `appointment_id`;
- `customer_id`;
- `service_id`;
- `confirmed` status.

It may update:

- `starts_at`;
- `ends_at`;
- `buffer_before_minutes`;
- `buffer_after_minutes`;
- `professional_id`, when an explicitly selected eligible Professional is different.

Rescheduling does not change the contracted Service.

### Current Service configuration

Use the Service's current authoritative scheduling configuration when rescheduling:

- `duration_minutes`;
- `buffer_before_minutes`;
- `buffer_after_minutes`.

Derive the new `ends_at` and complete scheduling occupancy from those values.

Update the Appointment's persisted effective timing and buffers only when rescheduling succeeds.

Existing Appointments that are not being rescheduled retain their persisted timing and buffer values.

The audit must capture the relevant previous and updated values.

### Professional selection

When no replacement Professional is supplied, retain the existing Professional.

When a Professional is explicitly selected, validate Business association, Service eligibility and availability.

Never select a different Professional automatically.

The existing S03 availability behavior remains responsible for providing eligible Professional availability and alternatives.

Conversational interpretation, negotiation and confirmation of an alternative Professional belong to S12, not S07.

### Scheduling availability

Reuse the existing S03 scheduling calculation and its PostgreSQL-backed occupancy integration from S05.

The only rescheduling-specific occupancy adjustment is excluding the existing Appointment from its own availability calculation.

All other confirmed Appointment occupancy remains effective.

The original Appointment must remain persisted and confirmed until the complete rescheduling transaction succeeds.

Do not duplicate the S03 scheduling algorithm, create a separate general availability capability or introduce a new scheduling framework.

Preserve all existing scheduling rules, including:

- Business operating hours;
- Professional recurring availability;
- Business and Professional scheduling exceptions;
- Professional-Service eligibility;
- current Service duration and buffers;
- half-open occupancy intervals;
- the existing two-minute, whole-minute-rounded start-time safety threshold;
- absence of overlapping confirmed Professional occupancy.

Normal Appointment availability queries and creation must continue accounting for every confirmed Appointment, without an exclusion.

### Rescheduling notice

`Business.rescheduling_notice_minutes` is an advisory recommendation in V1.

Evaluate notice against the original persisted Appointment start and the authoritative operation time.

An operation performed exactly at the configured notice boundary is compliant.

An otherwise valid rescheduling performed with less notice remains permitted but must be marked as late.

If no recommendation is configured, no late-notice incident is generated.

A successful late rescheduling produces a normal Appointment audit record with `late_notice = true`.

A successful compliant rescheduling produces a normal audit record with `late_notice = false`.

A failed rescheduling must not persist an audit record or late-notice incident.

### Lifecycle and ownership

Only an identified, registered Customer may reschedule an Appointment belonging to them.

Only future confirmed Appointments are eligible for normal Customer rescheduling.

Cancelled, completed and no-show Appointments cannot be rescheduled.

Ownership and lifecycle eligibility must be enforced by the application using current authoritative state.

The conversational layer must not determine whether a persistent Appointment may be modified.

### Transaction and concurrency

The Appointment update and its audit record must be persisted atomically in PostgreSQL.

The application must revalidate the original Appointment's state, ownership and requested scheduling conditions during the authoritative operation.

Protect against conflicting changes to the same Appointment and against overlapping bookings for the selected Professional.

Reuse the existing PostgreSQL exclusion constraint and established transaction conventions.

The original Appointment must not be released or cancelled before the replacement slot is successfully committed.

An identified PostgreSQL scheduling exclusion conflict produces a structured unavailable-slot result.

Unrelated technical failures must not be concealed as scheduling conflicts.

Do not introduce Redis locks or generalized concurrency infrastructure.

### Audit

Reuse the append-only Appointment audit mechanism implemented in S05.

A successful rescheduling generates exactly one corresponding audit record with action `rescheduled`.

The audit preserves:

- affected Appointment;
- operation timestamp;
- responsible actor;
- originating source;
- relevant before-state snapshot;
- relevant after-state snapshot;
- `late_notice`.

When the Professional changes, the reassignment must be visible in the same before/after audit record.

Do not create a redundant audit event solely because the Professional changed during rescheduling.

The Appointment modification and audit insertion must succeed or fail together.

### Result

Return structured application results distinguishing:

- successful rescheduling with the updated Appointment;
- invalid or inaccessible Appointment reference;
- invalid Customer identity;
- Appointment not eligible for rescheduling;
- invalid Professional selection;
- unavailable requested slot;
- invalid requested start;
- technical persistence failure.

Preserve existing application result conventions where they provide an equivalent representation.

Do not return conversational prose or silently choose an alternative slot.

---

## Scope boundaries

S07 does not implement:

- Appointment cancellation;
- Appointment completion or no-show transitions;
- replacement of the original Service;
- automatic modification of other Appointments when Service configuration changes;
- automatic Professional assignment;
- conversational clarification, negotiation or confirmation;
- AI tools or prompts;
- HTTP endpoints;
- WhatsApp or n8n integration;
- Professional notifications;
- Customer reminders;
- Google Calendar synchronization;
- administration interfaces;
- deposits, payments, penalties or Customer scoring;
- Customer incident counters or additional incident tables;
- generalized scheduling, reservation or locking infrastructure.

External integrations and conversational orchestration belong to their respective later slices.

---

# Task 1 — Appointment persistence alignment

## Goal

Align existing Appointment status and audit persistence with the approved V1 lifecycle and late-notice requirements.

## Implementation

Extend the Appointment status representation to support:

`no_show`

Update only the applicable:

- Appointment status enum or equivalent representation;
- Eloquent casts and model behavior;
- PostgreSQL status constraint;
- relevant persistence fixtures and tests.

Preserve the existing `confirmed`, `cancelled` and `completed` statuses.

Only `confirmed` Appointments must participate in authoritative scheduling occupancy and PostgreSQL overlap prevention.

Add to `appointment_audits`:

`late_notice BOOLEAN NOT NULL DEFAULT FALSE`

Add the corresponding Eloquent boolean cast.

Existing audit records must remain valid and receive `false` as the default value.

Preserve the established audit-writing mechanism, historical snapshots and append-only behavior.

Do not implement lifecycle transitions or rescheduling in this task.

## Acceptance criteria

- Appointment persistence supports all four documented statuses.
- Unsupported statuses remain invalid.
- `no_show` is a non-occupying historical state.
- The existing PostgreSQL exclusion constraint continues preventing overlapping confirmed Appointments.
- `late_notice` persists as a non-nullable boolean.
- Existing and newly created ordinary audit records default to `false`.
- An audit record may explicitly persist `late_notice = true`.
- Existing audit relationships and snapshots remain intact.
- No separate Customer incident ledger or counter is introduced.
- No cancellation, completion, no-show transition or rescheduling behavior is implemented.

## Tests

Cover at least:

1. Persistence of the four supported Appointment statuses.
2. Rejection of unsupported statuses.
3. `no_show` exclusion from authoritative occupancy.
4. Continued prevention of overlapping confirmed occupancy.
5. Existing audit records receiving the false default.
6. Explicit true and false `late_notice` persistence.
7. Existing audit-writing and historical snapshot compatibility.

Run the relevant existing Appointment persistence, occupancy and audit tests.

Do not implement Task 2 as part of this task.

---

# Task 2 — Rescheduling availability support

## Goal

Allow the existing S03 scheduling calculation to evaluate a replacement slot without treating the Appointment being rescheduled as conflicting occupancy.

## Implementation

Inspect the actual S03 availability calculation and S05 PostgreSQL-backed Appointment occupancy implementation.

Extend the existing scheduling path minimally to support excluding one specified Appointment from the occupancy used for its own rescheduling.

The exclusion must:

- identify the original Appointment;
- omit only that Appointment's occupancy;
- preserve the occupancy of every other confirmed Appointment;
- remain unavailable to ordinary Appointment creation and availability queries.

Prefer the smallest change compatible with the current S03 abstraction and its existing test doubles.

The trusted `RescheduleAppointment` capability will establish ownership and eligibility before using the exclusion during the authoritative mutation.

Do not create a separate scheduling algorithm or a new general-purpose availability capability.

Use the existing S03 rules and result conventions.

Current Service duration and buffers must determine the required replacement occupancy.

Preserve the existing Professional selection and availability behavior.

Do not introduce conversational logic for finding or choosing alternatives.

## Acceptance criteria

- An Appointment does not block its own replacement slot.
- Other confirmed Appointments continue blocking conflicting slots.
- The original Appointment is not deleted, cancelled or released during availability evaluation.
- Ordinary S03 availability queries retain existing behavior.
- `CreateAppointment` retains existing validation and occupancy behavior.
- Current Service duration and buffers determine replacement-slot validity.
- Professional eligibility, Business hours, Professional schedules and exceptions remain enforced.
- Half-open interval behavior is preserved.
- The established start-time safety threshold remains effective.
- No additional general availability capability or duplicate scheduling algorithm is introduced.

## Tests

Cover at least:

1. Valid replacement slot for the same Professional.
2. Original occupancy excluded from its own availability calculation.
3. Partially overlapping original and replacement occupancy.
4. Another confirmed Appointment preventing the target slot.
5. Adjacent half-open intervals without false conflicts.
6. Current Service buffers affecting the replacement slot.
7. Current Service duration affecting replacement availability.
8. Professional eligibility and scheduling exceptions.
9. Ordinary availability without an exclusion retaining all confirmed occupancy.
10. Existing controlled/fake occupancy testing compatibility.

Run the relevant S03 and S05 regression tests.

Do not implement Task 3 as part of this task.

---

# Task 3 — Reschedule Appointment application capability

## Goal

Implement secure, transactional rescheduling of an existing confirmed Appointment, including authoritative revalidation, optional Professional reassignment, late-notice auditing and concurrency protection.

## Implementation

Introduce:

`RescheduleAppointment`

Follow existing application conventions for dependency injection, capability execution and structured results.

### Identity and Appointment validation

Accept the agreed Business, Customer, Appointment identifier, requested start, optional Professional and trusted audit context.

Resolve the Appointment using authoritative Customer-scoped persistence.

Validate:

- existing registered Customer;
- Appointment ownership;
- Appointment association with the applicable Business;
- future confirmed Appointment status;
- valid requested start;
- selected Professional's Business association and Service eligibility.

An inaccessible or unrelated Appointment must not expose another Customer's information.

Do not recreate S04 identity resolution or S06 Appointment listing logic.

Use those existing application boundaries as appropriate.

### Target scheduling

Preserve the original Service.

Use the Service's current duration and buffers to derive the new `ends_at` and occupancy.

When no replacement Professional is supplied, retain the existing Professional.

When an alternative is explicitly selected, validate and preserve that choice.

Use the existing S03 calculation with Task 2's narrowly scoped self-occupancy exclusion.

Revalidate the requested target slot against current authoritative scheduling state.

Do not automatically choose another Professional or start time.

### Transactional update

Use one authoritative PostgreSQL transaction.

Within the operation:

1. Retrieve and lock the existing Appointment using the appropriate established Laravel/PostgreSQL mechanism.
2. Revalidate ownership, status and original scheduling state.
3. Resolve the effective Professional and current Service scheduling configuration.
4. Revalidate the requested slot, excluding only the original Appointment's occupancy.
5. Determine late-notice status against the original Appointment start and authoritative operation time.
6. Capture the before-state snapshot.
7. Update the existing Appointment's Professional, start, end and persisted buffers as applicable.
8. Append exactly one `rescheduled` audit record with the before/after snapshots and `late_notice`.
9. Commit the Appointment update and audit atomically.

The existing Appointment identity, Customer, Service and confirmed status must be preserved.

Do not create a second Appointment or temporarily cancel the original.

If the requested values produce no effective persisted change, do not manufacture a rescheduling audit or late-notice incident.

### Failure and concurrency handling

A failed operation must preserve the original Appointment and its previously committed audit history.

Use existing PostgreSQL exclusion protection against overlapping confirmed Professional occupancy.

Handle identified scheduling exclusion violations as unavailable-slot results.

Ensure concurrent changes to the same Appointment cannot bypass current-state revalidation.

Do not classify unrelated database or audit failures as scheduling conflicts.

### Application results

Return structured results consistent with the existing application layer.

Successful execution returns the updated Appointment.

Failure outcomes must distinguish relevant validation, eligibility, access and scheduling failures without exposing unrelated Customer data.

Do not perform customer-facing conversational responses or external side effects.

## Acceptance criteria

- An identified registered Customer can reschedule their own future confirmed Appointment.
- Another Customer cannot modify or inspect that Appointment through this capability.
- Cancelled, completed, no-show and past Appointments cannot be rescheduled.
- The original Appointment ID, Customer and Service remain unchanged.
- The same Professional is retained when no replacement is selected.
- An explicitly selected eligible Professional can be assigned.
- An invalid or unavailable Professional is not silently replaced.
- Updated timing and buffers reflect current Service configuration.
- Availability is revalidated immediately before persistence.
- The original Appointment does not conflict with its own replacement occupancy.
- Conflicting active Appointments remain protected by PostgreSQL.
- Otherwise valid late rescheduling is permitted.
- Successful late rescheduling records `late_notice = true`.
- Successful compliant rescheduling records `late_notice = false`.
- Notice is evaluated against the original Appointment start.
- A successful rescheduling generates exactly one `rescheduled` audit record.
- Professional reassignment is represented in that same audit record.
- Appointment and audit persistence are atomic.
- Failures preserve the original Appointment, its occupancy and audit history.
- No additional Appointment row is created.
- Application results remain structured and independent from AI or messaging.
- Existing creation, availability and Appointment retrieval behavior remain valid.

## Tests

Cover at least:

### Valid rescheduling

1. Successful rescheduling with the existing Professional.
2. Successful rescheduling with an explicitly selected eligible Professional.
3. Preservation of Appointment identity, Customer and Service.
4. No additional Appointment row.
5. Current Service duration and buffers persisted after the change.
6. Original occupancy released and replacement occupancy established after success.

### Authorization and validation

7. Missing, nonexistent or unregistered Customer.
8. Attempted modification of another Customer's Appointment.
9. Invalid or inaccessible Appointment reference.
10. Cancelled, completed, no-show and past Appointment rejection.
11. Invalid requested start and shared safety-threshold boundary.
12. Ineligible or other-Business Professional.
13. Unavailable replacement slot.
14. No automatic Professional substitution.

### Notice and audit

15. Rescheduling before the configured notice boundary.
16. Rescheduling exactly at the notice boundary.
17. Successful late rescheduling.
18. No configured notice recommendation.
19. Notice evaluated against the original start rather than the replacement start.
20. Single `rescheduled` audit containing the correct actor, source, before/after snapshots and `late_notice`.
21. Professional reassignment captured within the same audit.
22. Rollback when audit persistence fails.
23. No audit or late-notice incident after a failed or unchanged operation.

### Integration and concurrency

24. Concurrent creation and rescheduling competing for overlapping Professional occupancy.
25. Concurrent rescheduling requests competing for the same target occupancy.
26. Concurrent attempts to modify the same Appointment.
27. PostgreSQL exclusion conflicts producing the expected unavailable-slot result.
28. Failed rescheduling preserving the original Appointment and occupied interval.
29. Unrelated persistence failures remaining distinguishable from scheduling conflicts.
30. Existing S03 availability, S05 creation/audit and S06 retrieval regression coverage.
31. Reference Business scenario exercising the complete capability.

Use actual PostgreSQL concurrency behavior for tests intended to prove database exclusion or transactional integrity. Do not rely solely on mocks for those guarantees.

Run all required backend validation and relevant existing tests.

Do not implement future-slice capabilities.

---

# Slice acceptance

S07 is complete when:

- the Appointment status representation includes `no_show` without implementing its lifecycle transition;
- AppointmentAudit supports the `late_notice` boolean;
- only confirmed Appointments participate in scheduling occupancy;
- the existing S03 scheduling logic is reused;
- a rescheduling operation excludes only its original Appointment from occupancy validation;
- ordinary availability and creation retain existing behavior;
- `RescheduleAppointment` is available as a reusable Laravel application capability;
- Customer identity and ownership are enforced;
- only eligible future confirmed Appointments can be rescheduled;
- the same Appointment identity and Service are preserved;
- the existing Professional remains selected unless an eligible alternative is explicitly supplied;
- current Service duration and buffers are applied to the updated Appointment;
- target availability is authoritatively revalidated;
- late-notice recommendations do not prevent otherwise valid operations;
- successful late changes are recorded in the existing audit mechanism;
- every successful rescheduling has exactly one corresponding append-only audit record;
- Appointment updates and audits are atomic;
- failed operations preserve the original Appointment and its prior audit history;
- PostgreSQL continues preventing overlapping confirmed Professional occupancy;
- no new conversational, external integration or later lifecycle functionality has been introduced;
- all relevant and mandatory backend tests and checks pass.

After each task, run the relevant tests and complete required backend validation.

The Developer reviews and accepts each task before starting the next.

Do not create a Git commit. The Developer controls repository history.
