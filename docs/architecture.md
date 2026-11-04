# Architecture

## 1. Overview

Appointment Assistant uses Laravel as its authoritative application core.

The V1 follows a pragmatic modular-monolith architecture: the application is
deployed as a single backend system while maintaining clear boundaries between
application responsibilities.

Business rules and authoritative Appointment operations belong to the Laravel
application rather than to interaction channels, AI providers, workflow
automation or external integrations.

The architecture favors simplicity for V1 and avoids coupling core application
behavior to a specific interaction channel or external provider.

Product behavior and Business rules are defined in `docs/product.md`.

Architecturally significant decisions and their rationale are recorded in
`docs/adr/`.

---

## 2. System context

Appointment Assistant V1 is accessed through two customer-facing interfaces:

- WhatsApp customer interaction;
- a public React web experience containing the Appointment Assistant presentation, conversational demo and visual demo calendar.

The web demo allows visitors to interact with the real Appointment Assistant
backend without requiring them to use their personal WhatsApp account.

The demo calendar is a read-only visualization of a bounded set of demo
Appointments. It reflects Appointment creation, rescheduling and cancellation
performed through the conversational demo.

The calendar is rendered by the React application from Appointment data exposed
by Laravel. It is not an embedded Google Calendar and does not provide manual
Appointment management.

### External systems

V1 integrates with:

- OpenAI for conversational AI capabilities;
- WhatsApp Business services for customer and Professional messaging;
- Google Calendar as the Business operational schedule;
- n8n for selected provider-specific integration workflows.

```text
                         Customer
                            │
              ┌─────────────┴─────────────┐
              │                           │
          WhatsApp                    Web demo
              │                   React / TypeScript
              │                    Chat + Calendar
              ▼                           │
             n8n                          │
              │                           │
              └─────────────┬─────────────┘
                            ▼
                     ┌─────────────┐
                     │   Laravel   │
                     │ application │
                     │    core     │
                     └──────┬──────┘
                            │
              ┌─────────────┼─────────────┐
              ▼             ▼             ▼
         PostgreSQL       Redis         OpenAI
                            │
                            ▼
                      Queue workers

Laravel ──► n8n ──► WhatsApp
                └──► Google Calendar
```

---

## 3. Solution strategy

### Application architecture

- **Application core:** PHP / Laravel
- **Architectural style:** pragmatic modular monolith
- **Primary datastore:** PostgreSQL
- **Asynchronous processing:** Laravel Queues backed by Redis
- **External API style:** HTTP/JSON using REST-style conventions where appropriate
- **Frontend:** standalone React + TypeScript application built with Vite
- **Conversational AI:** Laravel AI SDK with OpenAI as the V1 provider
- **Workflow/integration automation:** n8n where it materially reduces
  provider-specific integration code
- **Operational schedule:** Google Calendar
- **Backend testing:** PHPUnit
- **Browser E2E testing:** Playwright
- **CI:** GitHub Actions
- **Backend deployment:** Docker Compose on a VPS
- **Public frontend deployment:** independently deployed static web application

Exact framework, dependency and runtime versions are defined by executable
configuration and lock files rather than duplicated in this document.

---

## 4. Application and interface boundaries

Laravel owns application behavior, Business rules and authoritative Appointment
operations.

External interfaces communicate with Laravel through explicit application
interfaces.

### Web

The React application communicates with Laravel through HTTP/JSON APIs.

The web application provides:

- a public presentation of Appointment Assistant, its purpose and core capabilities;
- the conversational demo;
- the read-only demo calendar.

The conversational interface invokes the same application capabilities used by
the real messaging channel.

The demo calendar reads Appointment state from Laravel and reflects changes made
through the conversation.

```text
                 React demo
              ┌──────┴──────┐
              │             │
            Chat         Calendar
              │             │
              └──────┬──────┘
                     ▼
                Laravel API
                     │
                     ▼
                Appointment
                   state
```

The frontend does not implement Appointment or scheduling rules.

The demo calendar does not create, modify or cancel Appointments directly.

### WhatsApp

WhatsApp messaging is integrated through n8n where its existing provider
integration materially reduces custom transport code.

```text
WhatsApp
   ↓
n8n
   ↓ HTTP/JSON
Laravel
```

Responses follow the inverse integration path.

n8n handles provider-specific transport concerns; Laravel determines what the
system should do.

### AI

Conversational AI is integrated through the Laravel AI SDK using OpenAI as the
V1 provider.

```text
Conversation
    ↓
AI agent
    ↓
tool request
    ↓
Laravel application capability
```

The AI layer interprets natural-language requests and selects appropriate
application capabilities.

AI tools delegate operations to Laravel.

They do not independently implement scheduling rules or directly modify
Appointment persistence.

### n8n

n8n is an integration and workflow automation component, not an authoritative
application component.

It is used where existing connectors or workflow capabilities materially reduce
custom provider-integration code.

Typical V1 uses include:

- inbound and outbound WhatsApp transport;
- Professional notifications through WhatsApp;
- Google Calendar synchronization.

Core Business behavior remains in Laravel even when n8n executes the external
integration.

---

## 5. Data and asynchronous processing

PostgreSQL is the primary persistent datastore for Appointment Assistant.

Durable Business and application state must not depend exclusively on Redis,
n8n, OpenAI, Google Calendar or messaging providers.

Business-specific configuration is persistent application data.

During V1 it may be populated through seeds or deployment processes rather than
being embedded in application source code.

Redis supports transient infrastructure concerns, primarily:

- Laravel queues;
- caching where useful;
- coordination where required.

Operations that do not need to complete before an authoritative application
operation succeeds may run asynchronously.

Examples include:

- Google Calendar synchronization;
- message delivery;
- Professional notifications;
- Customer reminders.

Authoritative Appointment state remains in the application even when an
external side effect is pending or fails.

The concrete jobs, retry policies, idempotency mechanisms and transaction
boundaries are defined during implementation planning.

---

## 6. Identity and security boundaries

Laravel is the common security boundary for application operations.

Different V1 interfaces establish identity using mechanisms appropriate to their
context.

For real Business interaction channels, Appointment management requires a registered Customer with a persistent application identity.

A registered Customer is associated with:

- a name;
- a persistent supported channel identity;
- optional phone contact data.

The channel identity is used to resolve the same Customer across subsequent Conversations.

A phone number is not a mandatory identity key, and mobile ownership verification is not required for V1 Customer registration or Appointment creation.

Customer identity is owned by the Laravel application and persists independently from Conversations and Appointments. It must not depend exclusively on Conversation state or the AI layer.

The public demo uses isolated temporary demo identities so that visitors can
exercise Appointment capabilities without creating real Business Customers.

Machine-to-machine integrations such as n8n use dedicated machine credentials.

Authentication evidence supplied by an interface does not replace application
authorization.

Laravel remains responsible for determining whether an identified actor may
perform an operation.

Security mechanisms provided by Laravel should be preferred over custom
implementations when they satisfy the requirement.

Infrastructure credentials and secrets are stored outside application source
code.

PostgreSQL and Redis are not exposed as public application interfaces.

External webhooks and machine-to-machine interfaces must be authenticated or
verified using mechanisms appropriate to their provider.

The LLM is not an authorization boundary and cannot override application
permissions or Business rules.

---

## 7. Testing and observability

### Testing

PHPUnit is the primary backend test framework.

Backend tests cover:

- application behavior;
- Business rules;
- persistence;
- authorization;
- API behavior;
- integration boundaries.

External providers should be replaced by fakes or test doubles in normal
automated tests where appropriate.

Playwright provides a limited E2E layer for critical browser flows.

In particular, E2E coverage should validate the public demonstration flow in
which a conversational Appointment operation produces the corresponding visible
change in the demo calendar.

```text
Conversation
    ↓
Appointment operation
    ↓
Laravel state change
    ↓
Calendar refresh
    ↓
Visible updated Appointment
```

CI runs automated validation through GitHub Actions.

### Observability

V1 uses structured application logging and basic operation correlation.

Logs and integration records must provide enough information to trace important
operations across:

- incoming requests;
- conversations;
- AI tool execution;
- asynchronous jobs;
- external integration calls.

Important operations should be traceable across their relevant application and
integration steps.

Failed asynchronous work and integration failures must remain diagnosable.

Sensitive information must not be unnecessarily included in logs.

A full distributed observability stack is not part of V1.

---

## 8. Deployment

The Appointment Assistant backend is deployed using Docker Compose on a VPS.

The backend runtime includes:

- Laravel HTTP application;
- Laravel queue workers;
- Laravel scheduler;
- PostgreSQL;
- Redis.

n8n runs as a separate integration service and communicates with Laravel through
authenticated HTTP/JSON interfaces.

The React/Vite frontend is deployed independently as a static web application
and communicates with the Laravel API over HTTPS.

```text
                    Internet
                       │
          ┌────────────┴────────────┐
          │                         │
          ▼                         ▼
   React/Vite frontend             VPS
     static hosting                 │
          │                  ┌──────┴──────┐
          │ HTTPS            │   Laravel   │
          └─────────────────►│     API     │
                             └──────┬──────┘
                                    │
                          ┌─────────┼─────────┐──────────┐
                          ▼         ▼         ▼          ▼
                     PostgreSQL   Redis    Workers     Scheduler



Laravel ◄──── authenticated HTTP/JSON ────► n8n
                                             │
                                     ┌───────┴────────┐
                                     ▼                ▼
                                  WhatsApp      Google Calendar
```

Only services requiring external traffic are publicly exposed.

PostgreSQL and Redis remain private to the backend environment.

The frontend and backend have independent deployment lifecycles while sharing
the Laravel application API as their integration boundary.
