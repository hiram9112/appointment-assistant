# ADR-0005 — Use Laravel Queues with Redis for asynchronous processing

**Status:** Accepted

## Context

Some V1 operations trigger external side effects such as Google Calendar synchronization, Professional notifications, Customer reminders and message delivery.

Those side effects should not make a valid Appointment operation depend on the availability or latency of an external provider.

## Decision

Use Laravel Queues backed by Redis for asynchronous processing.

Authoritative Appointment state is persisted first. External side effects that do not determine Appointment validity may then be dispatched for asynchronous execution.

PostgreSQL remains the authoritative persistent datastore.

## Consequences

- external provider failures do not invalidate successful Appointment operations;
- Customer-facing operations are less dependent on external latency;
- failed asynchronous work can be retried and diagnosed;
- Redis and queue workers become part of the runtime infrastructure;
- the system must handle eventual consistency between authoritative state and external systems.
