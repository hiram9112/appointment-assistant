# S01 — Business information

**Status:** Active  
**Depends on:** Milestone 0  
**Covers:** FR-01

## Goal

Provide configured customer-facing Business information through a deterministic Laravel application capability backed by authoritative PostgreSQL data.

---

## Design decisions

### Business persistence

`Business` is a persistent application entity.

V1 contains one Business, but neither persistence nor application behavior may depend on the Lúmina reference Business identity.

The initial `businesses` model contains:

- `name`;
- `description`, nullable;
- `timezone`;
- `address_line`, nullable;
- `postal_code`, nullable;
- `city`, nullable;
- `country_code`, nullable;
- `phone`, nullable;
- `email`, nullable;
- Laravel timestamps.

`timezone` is required.

Location remains part of `Business` in V1. Do not introduce separate `Location` or `Address` entities.

Business configuration is persisted application data, not `.env` configuration.

### Operating hours

Recurring Business opening hours are represented by a separate persistence model equivalent to:

`business_operating_hours`

containing:

- `id`;
- `business_id`;
- `weekday`;
- `opens_at`;
- `closes_at`.

A Business may have multiple operating intervals for the same weekday.

No interval for a weekday means the Business is closed that day.

Temporary Business scheduling exceptions are outside S01.

### Customer-facing operational configuration

Persist the following structured configuration on `Business`:

- `payment_methods`: nullable JSON/JSONB collection;
- `appointments_recommended`: nullable boolean;
- `arrival_lead_minutes`: nullable integer;
- `cancellation_notice_minutes`: nullable integer;
- `rescheduling_notice_minutes`: nullable integer.

Null represents information that is not configured.

Store structured facts rather than prewritten conversational responses.

S01 persists and exposes cancellation/rescheduling notice values but does not enforce those policies.

### Application capability

Introduce a reusable application capability conceptually named:

`GetBusinessInformation`

with behavior equivalent to:

```php
GetBusinessInformation::handle(Business $business): BusinessInformation
```

Follow existing backend naming and structural conventions where they provide an equivalent design.

The caller supplies the applicable `Business`. S01 does not introduce Business resolution, tenancy or SaaS infrastructure.

The capability may use Eloquent directly. Do not introduce a repository abstraction without a concrete need.

### Application result

Introduce a structured result/read model conceptually named:

`BusinessInformation`.

It exposes the customer-facing information relevant to FR-01:

- Business profile;
- location/contact information;
- timezone;
- recurring operating hours;
- payment methods;
- appointments-recommended value;
- arrival lead time;
- cancellation notice;
- rescheduling notice.

It does not expose the Eloquent model itself or unrelated persistence state.

The result contains structured facts, not conversational prose.

Missing optional information remains unavailable/null and must not be replaced with fabricated defaults.

---

## Scope boundaries

S01 does not implement:

- Services or Professionals;
- scheduling or Appointment availability;
- Business-level temporary schedule exceptions;
- Customers or Appointments;
- cancellation/rescheduling enforcement;
- AI tools or prompts;
- HTTP/API interfaces;
- external integrations;
- multi-Business/SaaS infrastructure;
- administration UI.

---

# Task 1 — Business entity and core persistence

## Goal

Establish the persistent `Business` entity and the core Lúmina reference profile.

## Implementation

Implement:

- `businesses` migration;
- `Business` Eloquent model;
- required casts/configuration;
- reference Business seed for the core profile;
- factory only if useful for tests;
- persistence and seed tests.

Use the applicable core Business values from `docs/reference-business.md`.

## Acceptance criteria

- a Business can be persisted and retrieved;
- timezone is required;
- optional profile/contact fields may be null;
- the reference Business core profile is seeded correctly;
- no Lúmina-specific application behavior is introduced;
- operating hours and operational configuration are not implemented yet.

## Tests

Cover at least:

1. complete core Business persistence;
2. nullable optional fields;
3. reference Business core seed data.

Do not implement Task 2 as part of this task.

---

# Task 2 — Business operating hours

## Goal

Represent recurring Business opening hours as structured persistent data reusable by FR-01 and later scheduling capabilities.

## Implementation

Implement:

- recurring operating-hours persistence;
- `Business` relationship;
- weekday/time representation following existing backend conventions;
- Lúmina recurring schedule seed data;
- relevant automated tests.

Use the canonical recurring schedule from `docs/reference-business.md`.

Do not implement temporary scheduling exceptions or availability calculations.

## Acceptance criteria

- operating intervals belong to a Business;
- each interval contains weekday, opening time and closing time;
- multiple intervals per weekday are structurally possible;
- absence of an interval represents a closed day;
- Lúmina's recurring schedule is seeded correctly.

## Tests

Cover at least:

1. interval persistence and Business association;
2. retrieval through the Business relationship;
3. reference schedule seed data;
4. closed-day representation.

Do not implement Task 3 as part of this task.

---

# Task 3 — Customer-facing Business configuration

## Goal

Persist the remaining structured Business facts required by FR-01.

## Implementation

Add:

- `payment_methods`;
- `appointments_recommended`;
- `arrival_lead_minutes`;
- `cancellation_notice_minutes`;
- `rescheduling_notice_minutes`;

with appropriate Eloquent casts.

Extend the reference Business seed using the applicable values from `docs/reference-business.md`.

Do not introduce prewritten chatbot answers or Appointment policy enforcement.

## Acceptance criteria

- payment methods persist as structured data;
- boolean configuration preserves the distinction between true, false and unknown where applicable;
- minute-based configuration is numeric;
- null represents unavailable configuration;
- the reference Business values are seeded correctly.

## Tests

Cover at least:

1. payment-method persistence/casting;
2. appointments-recommended persistence/casting;
3. minute-based configuration;
4. nullable configuration;
5. reference seed values.

Do not implement Task 4 as part of this task.

---

# Task 4 — Business information application capability

## Goal

Expose FR-01 information through a reusable application capability independent from any delivery interface.

## Implementation

Introduce:

- `GetBusinessInformation`;
- `BusinessInformation`;
- only the small supporting read/value objects required for a clear structured result;
- FR-01 behavior tests.

The capability receives the applicable `Business`.

Do not add Business-resolution infrastructure, HTTP/API interfaces or AI tools.

## Acceptance criteria

For configured data, the result accurately exposes:

- profile;
- location/contact information;
- timezone;
- recurring operating hours;
- payment methods;
- appointments-recommended value;
- arrival lead time;
- cancellation notice;
- rescheduling notice.

For optional data that is not configured:

- absence is preserved;
- no value is fabricated.

The capability returns structured data rather than customer-facing prose.

## Tests

Cover at least:

1. complete configured Business information;
2. unavailable optional information;
3. operating hours including a closed day;
4. structured operational configuration;
5. the Lúmina reference dataset successfully exercising the capability.

---

# Slice acceptance

S01 is complete when:

- FR-01 customer-facing Business information is authoritative PostgreSQL data;
- recurring operating hours are structured persistent data;
- required operational configuration is structured persistent data;
- the reference Business exercises the capability;
- `GetBusinessInformation` exposes a reusable structured result;
- unavailable information is not invented;
- no later-slice functionality has been introduced;
- all S01 tests and existing relevant backend validation pass.

After Task 4, run the complete backend validation required by the repository and review the resulting implementation against FR-01 and the applicable architecture/ADR constraints.
