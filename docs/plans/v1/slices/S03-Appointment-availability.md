# S03 — Appointment availability

**Status:** Active  
**Depends on:** S02  
**Covers:** FR-03; BR-01; BR-02; BR-03; BR-04; BR-05; BR-13

## Goal

Calculate valid Appointment availability from authoritative Business and scheduling state through deterministic Laravel application capabilities.

The conversational layer must not independently calculate Appointment availability.

---

## Design decisions

### Service scheduling buffers

`Service.duration_minutes` remains the Customer-facing Appointment duration.

Services additionally support:

- `buffer_before_minutes`;
- `buffer_after_minutes`.

Both are non-negative integer minutes and default to `0`.

The complete scheduling occupancy is calculated from:

```text
buffer_before_minutes
+ duration_minutes
+ buffer_after_minutes
```

The derived total is not persisted separately.

Concrete Lúmina buffer values belong to `docs/reference-business.md` as reference Business configuration.

### Professional recurring availability

Professional recurring availability is represented using positive continuous intervals.

Conceptually:

```text
professional_availability_intervals
- id
- professional_id
- weekday
- starts_at
- ends_at
```

Multiple intervals for the same Professional and weekday are allowed.

Recurring breaks are represented by gaps between available intervals.

Example:

```text
09:00–13:30
14:30–17:00
```

represents a `13:30–14:30` break.

### Scheduling exceptions

Temporary schedule changes must support:

- Business-wide exceptions;
- Professional-specific exceptions;
- temporary additional availability;
- blocked availability;
- partial-day and full-day periods.

Conceptually:

```text
schedule_exceptions
- id
- business_id
- professional_id nullable
- starts_at
- ends_at
- kind
- reason nullable
```

where `kind` distinguishes:

```text
available
blocked
```

Blocked periods take precedence over recurring or additional availability.

Professional availability outside normal Business operating hours is usable only when an explicit Business exception also makes that period available.

Scheduling exceptions represent scheduling configuration only and do not introduce HR, payroll or overtime concepts.

### Appointment occupancy boundary

S03 must calculate availability accounting for active Appointment occupancy, but authoritative Appointment persistence is introduced in S05.

S03 therefore defines only the minimal boundary required to supply occupied Professional intervals and proves the scheduling behavior using controlled test data.

Do not introduce partial Appointment persistence or a production implementation that assumes Appointment occupancy is always empty.

S05 will connect authoritative persisted active Appointments to the availability calculation established in S03.

### Availability representation

Do not introduce an arbitrary fixed booking grid unless a Business rule requires one.

Availability is exposed as structured valid Appointment start ranges associated with each Professional after Laravel has applied the applicable scheduling rules.

The conversational layer may interpret and present those ranges, but it must not independently determine whether an Appointment fits.

### Professional selection

Availability results must preserve explicit Professional preference and Professional identity.

An explicitly requested Professional must never be silently replaced.

When no Professional is requested, valid availability remains associated with each eligible Professional so the Customer can choose without automatic Professional prioritization.

---

## Scope boundaries

S03 does not implement:

- Customer persistence or identity;
- Appointment creation;
- authoritative Appointment persistence;
- Appointment lifecycle;
- transactional creation concurrency;
- Appointment audit;
- AI tools or conversation orchestration;
- HTTP or messaging interfaces;
- Google Calendar synchronization;
- notifications or reminders;
- administration UI;
- fixed booking-start intervals;
- advanced schedule optimization.

Availability returned by S03 does not reserve or lock a time.

---

# Task 1 — Scheduling baseline alignment

## Goal

Bring the existing canonical documentation and implemented Service configuration into alignment with the decisions already closed during S03 planning.

This is an alignment task, not an opportunity to redesign future slices.

The documentation changes in this task are limited to:

- `docs/product.md`;
- `docs/architecture.md`;
- `docs/reference-business.md`;
- `docs/plans/v1/v1.md`.

Do not modify ADRs or introduce additional planning documents.

## Documentation updates

### `docs/product.md`

Make the following specific corrections.

#### Service scheduling buffers

Update the applicable Service and scheduling requirements so that:

- a Service may define `buffer_before_minutes`;
- a Service may define `buffer_after_minutes`;
- both values are Business scheduling configuration;
- both default conceptually to zero when no buffer is configured;
- `duration_minutes` remains the Customer-facing Appointment duration;
- availability must account for the complete scheduling occupancy:
  - buffer before;
  - Service duration;
  - buffer after;
- the complete scheduling occupancy must fit inside continuous valid Professional availability;
- buffers consume Professional scheduling capacity but do not change the Customer-facing Service duration.

Do not add Lúmina-specific buffer values to Product.

#### Customer identity

Correct all existing Product language that currently makes a verified mobile phone mandatory for Customer identity or Appointment creation.

This includes the applicable V1 objectives, actor definitions, `FR-14`, `BR-07`, and any other directly inconsistent references in `product.md`.

The resulting Product rules must state that:

- a real Business Appointment still belongs to a persistent registered Customer;
- Customer registration is still required before creating a real Business Appointment;
- the Customer's name is persisted;
- the supported real interaction channel provides a persistent channel identity associated with the Customer;
- an existing associated channel identity resolves the same Customer in later Conversations;
- a phone number is optional Customer contact data;
- the assistant may request a contact phone during registration or booking;
- when the Customer provides a phone number, it may be persisted;
- refusal or failure to provide a phone number must not block Customer registration or Appointment creation;
- mobile ownership verification is not required for V1 Appointment creation;
- a phone number must not be treated as the mandatory identity key or mandatory proof of Customer identity.

Remove requirements whose only purpose was mandatory mobile verification, including duplicate prevention based specifically on a verified mobile.

Do not introduce provider-specific WhatsApp identifier semantics in Product.

### `docs/architecture.md`

Update only the existing Customer identity architecture where it currently assumes a verified mobile phone is mandatory.

Preserve these architectural decisions:

- Customer identity is owned by Laravel;
- Customer identity persists independently from Conversation state and the AI layer;
- the supported real channel provides a persistent channel identity used to resolve returning Customers;
- the V1 WhatsApp channel may associate its channel identity with the Customer;
- phone number is optional contact data rather than mandatory authentication evidence;
- phone verification is not required as an application boundary for Appointment creation.

Do not change unrelated WhatsApp transport, public demo identity, authentication, authorization, n8n, deployment or integration architecture.

Do not create or modify an ADR for this correction.

### `docs/reference-business.md`

Extend the Lúmina Service configuration with explicit scheduling buffers.

Use these reference values:

| Service                        | Duration | Buffer before | Buffer after |
| ------------------------------ | -------: | ------------: | -----------: |
| Initial beauty consultation    |   30 min |         0 min |        0 min |
| Deep facial cleansing          |   60 min |         5 min |       10 min |
| Hydrating facial treatment     |   60 min |         5 min |       10 min |
| Premium facial treatment       |   90 min |        10 min |       10 min |
| Laser hair removal — underarms |   20 min |         5 min |        5 min |
| Laser hair removal — full legs |   45 min |         5 min |       10 min |
| Firming body treatment         |   60 min |         5 min |       10 min |
| Wellness massage               |   60 min |         5 min |       10 min |

These values are reference Business configuration only.

Where the reference scheduling policy currently requires the complete Service duration to fit, update the wording so the complete scheduling occupancy, including configured buffers, must fit.

Do not introduce any Lúmina-specific behavior into generic Product rules or application logic.

### `docs/plans/v1/v1.md`

Make only the roadmap corrections required by the decisions already closed.

#### S03

Clarify that:

- active Appointment occupancy participates in availability calculation;
- S03 proves that behavior independently from authoritative Appointment persistence;
- the real persisted Appointment occupancy source is connected in S05.

Do not remove active Appointment occupancy from S03 acceptance.

#### S04

Keep S04 responsible for persistent Customer registration and identity.

Replace the existing mandatory-mobile-verification acceptance direction with:

- Customer registration is required before creating a real Business Appointment;
- name is persisted;
- supported channel identity is associated with the Customer;
- an existing channel identity resolves the same Customer;
- phone number is optional contact data;
- a provided phone number may be persisted;
- refusal to provide a phone number does not prevent registration or booking;
- Customer identity persists independently from Conversations and Appointments.

Remove the mobile-verification boundary from S04.

Do not otherwise design S04 implementation.

#### S05

Keep the existing dependency on S03 and S04.

Add the responsibility that:

- authoritative persisted active Appointments provide the real occupancy data consumed by the availability calculation established in S03.

Do not otherwise redesign S05.

Do not implement or expand future slices as part of these roadmap corrections.

## Service implementation alignment

Extend the existing `Service` persistence with:

```text
buffer_before_minutes
buffer_after_minutes
```

Requirements:

- integer minutes;
- non-negative;
- database default `0`;
- existing Service rows remain valid through the zero default;
- both values are persistent Business scheduling configuration.

Update only the existing Service infrastructure required for this change, following current repository conventions:

- schema/migration;
- `Service` model configuration as applicable;
- factory defaults;
- Lúmina reference seeding;
- relevant Service persistence/seeding tests.

The canonical Lúmina Services must seed the exact buffer values defined in `docs/reference-business.md`.

Repeated reference seeding must:

- create missing canonical reference Services;
- restore canonical buffer values for existing reference Services;
- not create duplicate canonical Services;
- preserve unrelated Services belonging to Lúmina.

Do not add Service buffers to the existing customer-facing `ServiceInformation` output solely because the fields now exist. They are scheduling configuration consumed by S03, not additional FR-02 customer-facing Service information.

Do not implement Professional scheduling configuration in this task.

## Acceptance criteria

- `product.md` contains the agreed generic Service buffer rules;
- `product.md` no longer requires a verified mobile phone to register or create a real Appointment;
- `architecture.md` no longer models verified mobile as mandatory Customer identity evidence;
- `reference-business.md` contains the agreed Lúmina buffer values;
- `v1.md` contains the agreed S03/S05 Appointment occupancy split;
- `v1.md` S04 reflects optional phone contact data without designing S04 implementation;
- no unrelated documentation or ADR is changed;
- Service buffers are persisted with zero defaults;
- existing Service rows remain compatible;
- Lúmina reference buffers seed deterministically;
- repeated Service seeding restores canonical reference values while preserving unrelated Services;
- existing S01/S02 behavior remains valid.

## Tests

Cover at least:

1. default zero buffers;
2. configured buffer persistence;
3. non-negative buffer configuration;
4. canonical Lúmina buffer values;
5. repeated reference seeding restores canonical buffer values;
6. repeated reference seeding preserves unrelated Services;
7. existing relevant Service behavior remains green.

Do not implement Task 2 as part of this task.

---

# Task 2 — Professional scheduling configuration

## Goal

Persist the authoritative Professional and Business scheduling configuration required for Appointment availability.

## Implementation

Introduce Professional recurring availability supporting:

- multiple intervals per weekday;
- unavailable weekdays through absence of intervals;
- recurring breaks through gaps between intervals.

Introduce temporary scheduling exceptions supporting:

- Business scope;
- Professional scope;
- `available`;
- `blocked`;
- partial-day periods;
- full-day periods;
- optional reason.

Use the applicable scheduling configuration from `docs/reference-business.md`.

Seed:

- Lúmina Professional recurring availability;
- recurring breaks through separate availability intervals;
- Laura's temporary block;
- Marta's temporary block;
- Claudia's full-day block;
- the Business early-closing exception.

Reference scheduling data must remain configuration rather than Business-specific application logic.

Follow existing Laravel persistence, relationship, factory and seeding conventions where they fit the agreed design.

## Acceptance criteria

- Professional recurring availability is persistent configuration;
- multiple intervals for the same weekday are supported;
- breaks can be represented through gaps;
- Business and Professional scheduling exceptions are persisted;
- positive and blocked exceptions are distinguishable for both scopes;
- Lúmina scheduling configuration is seeded deterministically.

## Tests

Cover at least:

1. recurring Professional availability persistence;
2. multiple intervals on one weekday;
3. unavailable weekday;
4. break represented by availability gaps;
5. Business-level exception;
6. Professional partial block;
7. Professional full-day block;
8. positive availability exception;
9. Lúmina recurring schedules;
10. Lúmina temporary exceptions;
11. deterministic repeated seeding.

Do not implement Task 3 as part of this task.

---

# Task 3 — Availability calculation

## Goal

Implement deterministic scheduling logic that calculates valid Appointment start availability from authoritative scheduling configuration.

## Implementation

Calculate effective availability using the existing:

- Business operating schedule;
- Professional-Service eligibility;

and the scheduling configuration established by Tasks 1 and 2:

- Professional recurring availability;
- Business and Professional scheduling exceptions;
- Service duration;
- Service scheduling buffers.

Account for active Appointment occupancy through the S03 occupancy boundary.

The complete scheduling occupancy of the requested Service must fit within continuous effective availability.

The calculation must produce valid Customer Appointment start ranges rather than raw Professional free periods.

Do not introduce a fixed booking-start grid.

Introduce only the interval structures required by S03. Do not build a generalized calendar framework.

Follow existing application/domain conventions where they fit the agreed behavior. Prefer the smallest implementation that keeps the scheduling calculation deterministic and independently testable.

## Appointment occupancy boundary

Introduce the smallest application boundary required to obtain occupied intervals for a Professional and relevant period.

Use controlled or fake occupancy data in S03 tests.

Do not introduce:

- Appointment persistence;
- Customer ownership;
- Appointment lifecycle;
- temporary fake production Appointment records.

The PostgreSQL-backed implementation is deferred to S05.

## Acceptance criteria

- Business operating hours constrain availability;
- Professional recurring availability constrains availability;
- breaks and unavailable periods are respected;
- Business and Professional exceptions modify effective availability correctly;
- blocked periods take precedence over recurring and additional availability;
- Professional availability outside normal Business hours requires an applicable Business availability exception;
- Service duration and buffers must fit completely;
- supplied Appointment occupancy removes otherwise valid availability;
- occupied periods can split continuous availability;
- adjacent non-overlapping periods do not create false conflicts;
- calculations remain independent from AI and external integrations.

## Tests

Cover at least:

1. Service fitting inside an available interval;
2. Service not fitting;
3. before buffer;
4. after buffer;
5. Business operating boundary;
6. Professional availability boundary;
7. recurring break;
8. Business scheduling exception;
9. Professional scheduling exception;
10. full-day block;
11. positive availability exception;
12. active Appointment occupancy;
13. occupancy splitting availability;
14. adjacent non-overlapping occupancy;
15. multiple resulting availability intervals;
16. blocked-period precedence over recurring and additional availability;
17. Professional availability outside Business hours with and without an applicable Business availability exception.

Do not implement Task 4 as part of this task.

---

# Task 4 — Appointment availability application capability

## Goal

Expose FR-03 through a reusable Laravel application capability using the scheduling calculation established in Task 3.

## Implementation

Introduce the application capability and structured read models required to query Appointment availability.

Conceptually, the capability receives:

- Business;
- Service;
- requested date/time range;
- optional Professional preference.

Follow the application-layer conventions established in S01 and S02.

Do not introduce conversational strategy parameters such as:

- `earliest`;
- `morning`;
- `around`;
- ranking or optimization strategies.

Natural-language interpretation belongs to the later conversational layer.

The capability must return structured valid Appointment start ranges associated with Professionals.

### Explicit Professional preference

When a Professional is supplied:

- ensure the Professional belongs to the Business;
- evaluate Professional-Service eligibility;
- evaluate that Professional's current availability;
- preserve their result explicitly;
- when appropriate, expose valid availability from other eligible Professionals as alternatives;
- never silently substitute another Professional.

### No Professional preference

When no Professional is supplied:

- calculate availability for eligible Professionals;
- preserve Professional identity in every result;
- use deterministic neutral ordering;
- do not automatically prioritize or assign a Professional.

### Application result

Return structured scheduling facts rather than Customer-facing prose.

The result must provide enough valid availability context for a later AI tool to conduct the conversation without independently calculating Appointment validity.

The exact capability, read-model and method names may follow the conventions that best fit the existing S01/S02 application layer, provided the behavior above is preserved.

## Acceptance criteria

- invalid, inactive and other-Business Services fail safely;
- eligibility comes from authoritative configuration;
- valid availability comes from the scheduling calculation established in Task 3;
- explicit Professional preference is preserved;
- an explicitly requested Professional is never silently substituted;
- valid alternatives can be returned when appropriate;
- availability without Professional preference preserves Customer choice;
- no Professional is automatically prioritized;
- output is structured and deterministic;
- no conversational scheduling calculation is required.

## Tests

Cover at least:

1. availability for one eligible Professional;
2. availability across multiple eligible Professionals;
3. no valid availability;
4. invalid Service;
5. inactive Service;
6. other-Business Service;
7. requested Professional eligible and available;
8. requested Professional eligible but unavailable;
9. requested Professional ineligible;
10. alternatives for an unavailable requested Professional;
11. no silent substitution;
12. no automatic Professional prioritization;
13. deterministic Professional ordering;
14. structured availability ranges;
15. Lúmina reference scenarios exercising the capability.

---

# Slice acceptance

S03 is complete when:

- Service scheduling buffers participate in authoritative availability calculation;
- Professional recurring availability and scheduling exceptions are persistent Business configuration;
- valid Appointment availability is calculated from authoritative Business and scheduling state;
- active Appointment occupancy affects availability through the S03 boundary, with authoritative persistence integration explicitly deferred to S05;
- explicit Professional preference and Professional choice are preserved without silent substitution or automatic prioritization;
- Laravel exposes deterministic structured availability without relying on conversational scheduling logic;
- Lúmina reference configuration exercises the implementation;
- all S03 tests and existing relevant backend validation pass.

After Task 4, run the complete backend validation required by the repository and perform the Milestone 1 integration checkpoint before marking S03 `Done`.
