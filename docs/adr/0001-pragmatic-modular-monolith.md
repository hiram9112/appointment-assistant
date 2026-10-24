# ADR-0001 — Use a pragmatic modular monolith

**Status:** Accepted

## Context

Appointment Assistant needs a clear separation between Business rules, conversational interaction and external integrations, but the V1 does not justify the operational complexity of microservices or a strict Clean/Hexagonal architecture.

A traditional global MVC structure would be simpler initially but could make responsibilities increasingly coupled as the application grows.

## Decision

Use Laravel as the authoritative application core following a pragmatic modular-monolith architecture.

The application will remain a single backend system and primary deployment while keeping clear boundaries between application responsibilities.

Clean or hexagonal principles may be applied selectively when they improve separation or testability, without enforcing a strict architectural ceremony.

The concrete internal modules and directory structure will be defined during implementation planning.

## Consequences

- simpler deployment and transactional consistency;
- Business rules remain centralized in Laravel;
- internal boundaries require discipline;
- capabilities can be extracted later only if a concrete technical need justifies it.
