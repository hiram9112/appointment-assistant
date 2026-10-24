# ADR-0004 — Integrate conversational AI through Laravel AI SDK

**Status:** Accepted

## Context

Appointment Assistant requires an LLM to interpret natural-language requests, maintain conversational interaction and invoke application capabilities.

The project could integrate directly with the OpenAI API or introduce a custom AI abstraction, but that would require more provider-specific integration code.

The AI layer must not own scheduling rules, authorization or authoritative Appointment state.

## Decision

Use Laravel AI SDK as the conversational AI integration layer, with OpenAI as the V1 provider.

The AI layer interprets Customer intent and invokes Laravel application capabilities through tools.

Business rules, validation, authorization and Appointment state remain inside the Laravel application.

## Consequences

- reduces provider-integration boilerplate;
- keeps AI tooling aligned with the Laravel application;
- Business logic remains independent from prompts and provider behavior;
- introduces dependency on Laravel AI SDK;
- provider-specific capabilities may require direct integration later if the SDK does not expose them.
