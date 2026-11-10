# S09 — Complete Appointment lifecycle

**Status:** Done  
**Depends on:** S05  
**Covers:** BR-06; BR-14

## Goal

Complete the V1 Appointment lifecycle by allowing trusted application callers to explicitly record whether a confirmed Appointment was completed or the Customer did not attend.

Both outcomes must preserve Appointment history, enforce valid state transitions and persist the corresponding audit atomically.

Reuse the existing Laravel application and PostgreSQL persistence mechanisms.

---

## Design decisions

### Application capability

Introduce:

`RecordAppointmentAttendance`

Conceptual inputs:

- applicable `Business`;
- existing `appointmentId`;
- explicit attendance outcome: `completed` or `no_show`;
- trusted audit context containing `actor` and `source`.

Follow the established application-layer conventions for structured results, transactions and audit recording.

The capability must remain independent of the interface supplying the attendance confirmation.

### Authorization boundary

Attendance recording is an operational Business action, not a Customer Appointment operation.

Only a trusted application caller may invoke this capability. Authentication and operator authorization must be enforced by the future entry point before invoking it.

The capability must validate the Appointment's association with the applicable Business.

Audit metadata identifies the actor and source but is not proof of authorization.

S09 does not introduce authentication, roles, permissions or public endpoints.

### Lifecycle and temporal rules

Support only:

- `confirmed → completed`
- `confirmed → no_show`

The requested outcome must be explicit.

Reject transitions from `cancelled`, `completed` or `no_show`, including repeated or conflicting attendance confirmations.

An attendance outcome may be recorded only when the Appointment's scheduled start has been reached:

`starts_at <= operationTime`

Reaching the scheduled end is not required for an explicit authorized confirmation. A Service may finish earlier than its planned duration.

The application must never infer attendance or non-attendance from elapsed time or missing confirmation.

Preserve the existing Appointment identity, Customer, Service, Professional, scheduled times and buffers.

### Persistence and audit

Reuse PostgreSQL transactions, `lockForUpdate()` and `AppendAppointmentAudit`.

Retrieve and lock the Appointment, then revalidate its Business association, current status and temporal eligibility.

Update only the Appointment status and normal persistence timestamps.

Append exactly one audit record using the corresponding action:

- `completed`
- `no_show`

The audit must include the operation timestamp, trusted actor/source and relevant before/after snapshots.

Attendance recording must not generate a late-notice incident. The existing audit field `late_notice` remains `false`.

The Appointment update and audit insertion must commit or roll back together.

### Scheduling occupancy

Only confirmed Appointments occupy scheduling availability.

After a successful transition, `completed` and `no_show` Appointments must no longer contribute to occupancy.

Reuse the existing PostgreSQL-backed occupancy behavior without modifying scheduling calculations or overlap constraints.

### Future operational integration

Attendance will be explicitly confirmed by an authorized operator through the future Business Admin.

That interface may identify pending confirmations using:

`status = 'confirmed' AND ends_at <= now()`

This condition is for identifying unresolved Appointments, not for automatically determining attendance and not for restricting the application capability.

S09 implements only the authoritative backend operation. Business Admin UI, operator authentication and pending-attendance presentation remain outside this slice.

---

## Scope boundaries

S09 does not implement:

- Appointment creation, rescheduling or cancellation;
- automatic attendance determination or state transitions;
- Business Admin UI, authentication or permissions;
- notifications, reminders or WhatsApp attendance requests;
- HTTP endpoints or conversational AI tools;
- new persistence tables or migrations;
- changes to availability calculations;
- Customer incident counters, scoring or penalties;
- generalized lifecycle, authorization or transaction frameworks.

---

# Task 1 — Record Appointment attendance

## Goal

Implement a reusable Laravel capability that records an explicit attendance outcome for an eligible Appointment and persists the lifecycle transition with its audit atomically.

## Implementation

Introduce `RecordAppointmentAttendance` following the existing Appointment application conventions.

The capability must:

1. Validate the requested outcome and trusted request context.
2. Retrieve the Appointment scoped to the applicable Business and lock its row.
3. Revalidate that the Appointment is `confirmed` and its scheduled start has been reached.
4. Capture the existing Appointment state.
5. Update the status to the explicitly requested terminal outcome.
6. Append the corresponding `completed` or `no_show` audit record.
7. Commit the status change and audit atomically.

Reuse existing models, transaction handling, snapshot conventions and structured result patterns.

Do not duplicate lifecycle or auditing infrastructure.

Technical persistence failures must remain distinguishable from expected validation failures.

## Acceptance criteria

- A trusted application caller can record `completed` or `no_show` for an eligible confirmed Appointment.
- The Appointment must belong to the applicable Business.
- Attendance cannot be recorded before the scheduled start.
- Explicit confirmation is allowed after the scheduled start without requiring the scheduled end to have passed.
- Cancelled, completed and no-show Appointments cannot transition again through this capability.
- Unsupported outcomes are rejected.
- No attendance outcome is inferred or applied automatically.
- The existing Appointment and its scheduling information are preserved.
- Each successful transition generates exactly one audit with the correct action, actor, source and before/after states.
- Attendance audits have `late_notice = false`.
- Failed operations preserve the original state and audit history.
- Concurrent operations cannot produce conflicting terminal states or duplicate successful-transition audits.
- Completed and no-show Appointments do not occupy availability.
- Normal Customer cancellation and rescheduling cannot modify terminal Appointments.
- Existing S05–S08 behavior remains valid.

## Tests

Use focused tests and the existing PostgreSQL testing infrastructure.

### Lifecycle and validation

- Successful `confirmed → completed` and `confirmed → no_show`.
- Valid Appointment scoped to the correct Business.
- Rejection of unrelated or nonexistent Appointments.
- Rejection of transitions from `cancelled`, `completed` and `no_show`.
- Rejection of unsupported outcomes.
- Repeated requests do not generate additional transitions or audits.
- Temporal boundary: reject before `starts_at`, allow exactly at `starts_at`.
- Authorized explicit confirmation does not require `ends_at` to have passed.

### Audit and transactional integrity

- Correct action, actor, source, timestamp and before/after snapshots.
- Historical audits remain unchanged.
- Attendance audits persist `late_notice = false`.
- Audit insertion failure rolls back the status change.
- Unrelated persistence failures remain technical failures.

### Occupancy and concurrency

- Both terminal outcomes stop contributing to confirmed Appointment occupancy.
- Concurrent attempts to record different outcomes cannot both succeed.
- Subsequent normal Customer cancellation or rescheduling rejects terminal Appointments.

Reuse existing PostgreSQL concurrency testing patterns without introducing new infrastructure.

### Regression validation

Run the relevant Appointment persistence, occupancy, audit, cancellation and rescheduling tests.

Run the complete backend test suite and mandatory lint checks.

Do not implement future-slice functionality.

---

# Slice acceptance

S09 is complete when:

- `RecordAppointmentAttendance` supports both explicit terminal outcomes;
- Business association, lifecycle and temporal rules are enforced;
- successful transitions are persisted and audited atomically;
- failed and concurrent operations preserve consistent Appointment state;
- existing confirmed-only occupancy behavior remains correct;
- no automatic attendance determination or external interface has been introduced;
- all relevant backend tests and mandatory checks pass.

The Developer reviews and accepts the implementation before marking S09 as `Done`.

Do not create a Git commit. The Developer controls repository history.
