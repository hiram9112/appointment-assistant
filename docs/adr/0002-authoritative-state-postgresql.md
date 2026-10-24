# ADR-0002 — Keep authoritative application state in PostgreSQL

**Status:** Accepted

## Context

Appointment Assistant integrates with external systems such as Google Calendar, n8n, OpenAI and messaging providers.

Those systems may represent, transport or synchronize application data, but relying on them as the source of truth would couple Appointment state to external provider availability and behavior.

The scheduling domain also requires relational consistency, transactional operations and concurrency control.

## Decision

Use PostgreSQL as the primary persistent datastore and authoritative source of application state.

Appointment state, Business configuration and other durable domain data are owned by the Laravel application and persisted in PostgreSQL.

External systems may synchronize or consume that state but must not become authoritative for Appointments.

Redis remains limited to transient infrastructure concerns such as queues and caching.

## Consequences

- external integration failures do not invalidate persisted Appointment state;
- Google Calendar becomes a synchronized operational representation rather than the source of truth;
- consistency and concurrency remain under application control;
- synchronization failures require retry or reconciliation mechanisms.
