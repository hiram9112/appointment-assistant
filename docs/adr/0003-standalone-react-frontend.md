# ADR-0003 — Use a standalone React and TypeScript frontend

**Status:** Accepted

## Context

The V1 requires a public web experience for presenting Appointment Assistant and running the conversational demo and read-only calendar.

Laravel could render the frontend using Blade, Livewire or an integrated React approach such as Inertia.

However, the application core is also consumed by non-web interfaces, and the project benefits from keeping the web frontend independently deployable.

## Decision

Use a standalone React and TypeScript frontend built with Vite.

The frontend communicates with Laravel through HTTP/JSON APIs.

Laravel remains responsible for Business rules, scheduling validation and authoritative Appointment state.

The frontend is responsible for presentation and user interaction.

## Consequences

- clear frontend/backend boundary;
- independent frontend deployment and evolution;
- backend capabilities remain reusable by other interfaces;
- requires explicit API design and frontend/backend integration;
- two build/deployment lifecycles must be maintained.
