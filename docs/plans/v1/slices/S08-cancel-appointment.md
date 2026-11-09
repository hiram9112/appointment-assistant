# S08 — Cancel Appointment

**Status:** Done  
**Depends on:** S05, S06  
**Covers:** FR-07; FR-11; BR-06; BR-07; BR-09; BR-10; BR-14

## Goal

Allow an identified, registered Customer to cancel their own future confirmed Appointment according to the Business cancellation policy.

Cancellation must preserve Appointment history, release scheduling occupancy and persist the state change with its corresponding audit record atomically.

Reuse the existing Laravel application, PostgreSQL transaction and Appointment audit mechanisms.

---

## Design decisions

### Application capability

Introduce:

`CancelAppointment`

Conceptual inputs:

- applicable `Business`;
- identified, registered `Customer`;
- existing `appointmentId`;
- trusted audit context containing `actor` and `source`.

Follow the established application conventions used by `RescheduleAppointment`, including structured outcomes and Customer-scoped Appointment access.

The capability must not resolve external channel identities or interpret conversational requests.

### Identity and lifecycle

Validate that the Customer is registered and owns the requested Appointment.

Only future confirmed Appointments may be cancelled through the normal Customer operation.

The permitted transition is:

`confirmed → cancelled`

Reject cancellation of past, already cancelled, completed or no-show Appointments.

Preserve the existing Appointment record, including its Customer, Service, Professional, scheduling timestamps and buffers.

Do not create replacement Appointments or implement other lifecycle transitions.

### Cancellation notice

Use `Business.cancellation_notice_minutes`, independently of the rescheduling notice configuration.

Compare the original Appointment start with the authoritative operation time.

The recommendation is advisory:

- cancellation before or exactly at the configured boundary is compliant;
- otherwise valid cancellation with less notice remains permitted;
- an unset recommendation generates no late-notice incident.

A successful cancellation records `late_notice` in its normal Appointment audit.

Failed cancellations must not generate audit records or late-notice incidents.

### Scheduling occupancy

Only confirmed Appointments occupy scheduling availability.

Changing the status to `cancelled` releases the original occupancy when the transaction commits.

Reuse the existing PostgreSQL-backed occupancy behavior without modifying S03 or the Appointment overlap constraint.

Cancellation does not require availability calculation, Professional eligibility validation or checking whether the original Service remains active.

### Transaction and audit

Reuse the transaction and row-locking conventions established in S07.

Within a PostgreSQL transaction:

1. Retrieve and lock the Customer-owned Appointment.
2. Revalidate ownership and lifecycle eligibility.
3. Evaluate the cancellation notice.
4. Capture the before-state snapshot.
5. Update the Appointment status to `cancelled`.
6. Append one audit record with action `cancelled`, before/after snapshots, trusted actor/source and `late_notice`.
7. Commit atomically.

Reuse `AppendAppointmentAudit` and the existing snapshot conventions.

If the operation fails, preserve the original Appointment and its previous audit history.

Concurrent operations must not bypass lifecycle revalidation or produce duplicate cancellation audits.

Technical persistence failures must remain distinguishable from expected validation failures.

### Application result

Return structured results consistent with existing Appointment capabilities.

Distinguish:

- successful cancellation, returning the updated Appointment;
- invalid Customer identity;
- invalid or inaccessible Appointment;
- Appointment not eligible for cancellation;
- invalid request context;
- technical persistence failure.

Do not generate customer-facing conversational responses.

---

## Scope boundaries

S08 does not implement:

- Appointment creation or rescheduling;
- completion or no-show transitions;
- automatic cancellation;
- conversational interpretation or confirmation;
- AI tools or prompts;
- HTTP endpoints;
- WhatsApp or n8n integration;
- notifications, reminders or calendar synchronization;
- deposits, penalties, refunds or Customer scoring;
- new scheduling algorithms;
- new persistence tables or migrations;
- administration interfaces;
- generalized transaction or locking infrastructure.

External integrations and additional lifecycle operations belong to their respective later slices.

---

# Task 1 — Cancel Appointment application capability

## Goal

Implement a reusable Laravel capability that cancels an eligible Appointment, releases its scheduling occupancy and records the corresponding audit atomically.

## Implementation

Introduce `CancelAppointment` following the current application-layer conventions.

Reuse the established:

- Customer identity and ownership validation;
- Appointment model and lifecycle statuses;
- PostgreSQL transaction and row locking;
- `AppendAppointmentAudit`;
- `AppointmentAudit.late_notice`;
- confirmed-only Appointment occupancy behavior.

The capability must validate the current Appointment state under lock and perform only the `confirmed → cancelled` transition.

Use the configured cancellation notice without treating a late request as invalid.

Preserve original Appointment information and historical audit records.

Do not introduce new abstractions where existing application code already supports the required behavior.

## Acceptance criteria

- An identified registered Customer can cancel their own future confirmed Appointment.
- Invalid Customer identity or ownership cannot modify Appointment state.
- Past, cancelled, completed and no-show Appointments cannot be cancelled.
- Successful cancellation preserves the Appointment ID and original scheduling information.
- The Appointment status becomes `cancelled`.
- The cancelled Appointment no longer occupies Professional availability.
- The cancellation recommendation is independent of the rescheduling recommendation.
- Cancellation exactly at the notice boundary is compliant.
- Otherwise valid late cancellation succeeds and records `late_notice = true`.
- Compliant cancellation records `late_notice = false`.
- An unset recommendation does not generate a late-notice incident.
- Exactly one `cancelled` audit is appended on success.
- Existing audit history is preserved.
- Appointment and audit persistence are atomic.
- Failed or repeated cancellation does not generate an additional audit.
- Concurrent operations cannot bypass lifecycle validation.
- Existing S03–S07 behavior remains unchanged.

## Tests

Cover the following behavior using focused tests and existing infrastructure.

### Cancellation and authorization

- Successful cancellation with preserved Appointment identity and original information.
- Registered Customer identity and ownership restrictions.
- Invalid, unrelated, past and non-confirmed Appointments.
- Repeated cancellation without duplicate audit records.

### Notice and audit

- Cancellation strictly before the notice boundary.
- Cancellation exactly at the boundary.
- Valid late cancellation.
- Unconfigured notice recommendation.
- Independence from `rescheduling_notice_minutes`.
- Correct audit action, actor/source, timestamp, before/after snapshots and `late_notice`.
- Rollback when audit persistence fails.

### Occupancy and concurrency

- Cancelled Appointment no longer contributes to occupancy.
- Released scheduling capacity can be used by a valid new Appointment.
- Concurrent cancellation attempts do not create duplicate successful transitions.
- Cancellation competing with rescheduling respects row locking and current-state revalidation.

Reuse existing PostgreSQL concurrency testing conventions. Do not duplicate unrelated S07 test infrastructure or introduce a generalized framework.

### Regression validation

Run relevant tests for:

- S03 availability;
- S05 Appointment creation and audit;
- S06 Appointment retrieval;
- S07 rescheduling.

Run the complete backend test suite and mandatory lint checks.

Do not implement future-slice functionality.

---

# Slice acceptance

S08 is complete when:

- `CancelAppointment` provides the required internal application capability;
- identity, ownership and lifecycle restrictions are enforced;
- eligible future confirmed Appointments transition to `cancelled`;
- cancellation preserves the existing Appointment and its historical information;
- committed cancellation releases scheduling occupancy;
- configured cancellation notice is applied as an advisory recommendation;
- successful cancellation generates exactly one audit with the correct `late_notice` value;
- failures preserve the original Appointment and audit history;
- concurrent operations respect authoritative lifecycle state;
- no unrelated functionality or infrastructure has been introduced;
- all relevant backend validation passes.

The Developer reviews and accepts the implementation before marking S08 as `Done`.

Do not create a Git commit. The Developer controls repository history.
