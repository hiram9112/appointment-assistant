# ADR-0006 — Use n8n selectively for provider-specific integrations

**Status:** Accepted

## Context

Appointment Assistant integrates with external providers such as WhatsApp and Google Calendar.

Laravel could implement these integrations directly, but doing so would require provider-specific code for webhooks, authentication, payload mapping, API calls and delivery workflows.

Using n8n as the central orchestrator for application behavior would instead split Business logic across multiple systems.

## Decision

Use n8n selectively when its connectors or workflow capabilities materially reduce provider-specific integration code.

Laravel remains responsible for Business rules, scheduling decisions, authorization and authoritative Appointment state.

n8n is limited to integration concerns such as WhatsApp transport, Professional notifications and Google Calendar synchronization.

## Consequences

- reduces custom provider-integration code;
- keeps Business logic centralized in Laravel;
- adds n8n as an operational dependency for selected integrations;
- workflow boundaries must be kept explicit to avoid moving domain logic into n8n.
