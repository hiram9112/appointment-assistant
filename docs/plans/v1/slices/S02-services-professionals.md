# S02 — Services and Professionals

**Status:** Done  
**Depends on:** S01  
**Covers:** FR-02; BR-01; BR-02

## Goal

Expose the Business Service catalogue, authoritative Service configuration, Professionals, and Professional-Service eligibility through deterministic Laravel application capabilities.

---

## Design decisions

### Service persistence

`Service` is persistent Business configuration.

Expected `services` data:

- `id`;
- `business_id`;
- `name`;
- `description`, nullable;
- `duration_minutes`;
- `price_amount`, nullable;
- `currency_code`, nullable;
- `active`;
- Laravel timestamps.

A Service belongs to one Business.

A Business has many Services.

`duration_minutes` is authoritative configuration and is later used by scheduling logic.

`price_amount` must use an exact decimal representation rather than floating point.

`price_amount = 0` represents a configured free Service.

`price_amount = null` represents an unavailable or unconfigured price.

`currency_code` uses the configured currency code for the price, such as `EUR`.

Inactive Services remain persistent data but are not exposed as available customer-facing Services.

### Professional persistence

Expected `professionals` data:

- `id`;
- `business_id`;
- `first_name`;
- `last_name`;
- `display_name`, nullable;
- Laravel timestamps.

A Professional belongs to one Business.

A Business has many Professionals.

`id` is the internal Professional identity.

Names are not identifiers and must not be unique.

Two Professionals may share the same `first_name`, `last_name`, or complete configured name.

`last_name` may contain the surname or surnames the Business chooses to configure.

`display_name` is optional customer-facing display information.

When configured, `display_name` may provide a public name or alias that allows Customers to distinguish a Professional from others.

`display_name` is not an identifier and must not be unique.

When no explicit `display_name` is configured, customer-facing application results use the Professional's configured first and last name as the display name.

Do not introduce Professional availability, breaks, scheduling exceptions, specialty classification or additional Professional identity infrastructure in S02.

### Professional-Service eligibility

Professional eligibility for Services is represented through a many-to-many relationship using:

`professional_service`

containing:

- `professional_id`;
- `service_id`.

The pair must be unique.

Both columns use foreign keys to their corresponding entities.

The relationship contains no additional V1 metadata.

A Professional may provide multiple Services and a Service may be provided by multiple Professionals.

Professional-Service eligibility is authoritative Business configuration and must not be inferred by AI or conversational logic.

For V1, the pivot schema does not introduce composite Business constraints. Cross-Business eligibility is rejected at the application capability boundary in Task 4.

### Reference configuration

Use the applicable Service, Professional and eligibility configuration from `docs/reference-business.md`.

Reference seeding is development/reference-data initialization and should be repeatable for the expected development workflow.

Canonical reference records should be restored when reseeded without treating the seed as a production catalogue synchronization mechanism.

Unrelated Business configuration must not be deleted merely because it is absent from `docs/reference-business.md`.

The Lúmina reference Professionals have no configured public aliases unless `docs/reference-business.md` explicitly defines one.

Professional-Service eligibility should converge to the canonical reference configuration when the seed is executed repeatedly.

Do not seed Professional availability or later scheduling configuration in S02.

### Application capabilities

Introduce two application capabilities following the application-layer pattern established by S01.

Conceptually:

```php
ListServices::handle(Business $business): array
```

and:

```php
GetServiceInformation::handle(
    Business $business,
    int $serviceId
): ?ServiceInformation
```

`ListServices` returns only active Services belonging to the supplied Business.

`GetServiceInformation` returns a Service only when it:

- exists;
- is active;
- belongs to the supplied Business.

An inactive, nonexistent or other-Business Service returns `null`.

Technical persistence failures remain technical failures and must not be converted into fabricated Service data.

### Application result

Use a structured read model conceptually equivalent to:

`ServiceInformation`

containing:

- `id`;
- `name`;
- `description`;
- `durationMinutes`;
- `priceAmount`;
- `currencyCode`;
- `eligibleProfessionals`.

Eligible Professionals are represented by a small read model conceptually named:

`ProfessionalSummary`

containing:

- `id`;
- `firstName`;
- `lastName`;
- `displayName`.

`displayName` is the customer-facing Professional name:

- use the configured `display_name` when present;
- otherwise fall back to the configured first and last name.

The capability result contains structured facts, not customer-facing conversational prose.

Eligible Professionals are not the same as available Professionals.

S02 does not calculate Appointment availability.

### Ordering

For deterministic capability output:

- Services are ordered by `name`;
- eligible Professionals are ordered by `first_name`, `last_name`, then `id`.

No configurable catalogue `sort_order` is introduced in S02.

---

## Scope boundaries

S02 does not implement:

- Professional recurring availability;
- Professional breaks;
- temporary availability exceptions or blocks;
- Appointment availability calculation;
- Professional selection behavior;
- ambiguity-resolution conversational flows;
- Appointment creation or modification;
- AI tools or prompts;
- HTTP/API interfaces;
- administration UI;
- SaaS/tenant infrastructure.

---

# Task 1 — Service persistence

## Goal

Introduce Services as authoritative Business configuration.

## Implementation

Implement:

- `services` migration;
- `Service` Eloquent model;
- `Business` ↔ `Service` relationships;
- appropriate casts for Service configuration;
- deterministic Lúmina Service seed data;
- relevant automated tests.

Use the applicable Service configuration from `docs/reference-business.md`.

Do not introduce Professionals yet.

## Acceptance criteria

- a Service belongs to one Business;
- a Business retrieves its Services;
- name and duration are required;
- description may be null;
- price may be null;
- configured zero price remains distinct from null;
- duration is stored as numeric minutes;
- price uses exact decimal persistence;
- active status is persisted;
- Lúmina's reference Services are seeded correctly;
- repeated reference seeding restores canonical reference Service configuration without deleting unrelated Services.

## Tests

Cover at least:

1. complete Service persistence;
2. Business relationship;
3. nullable description and price;
4. zero price distinct from null;
5. duration and active-state persistence;
6. reference Service seed data;
7. repeated reference seeding is idempotent for canonical reference Services and preserves unrelated Services.

Do not implement Task 2 as part of this task.

---

# Task 2 — Professional persistence

## Goal

Introduce Professionals as persistent Business configuration.

## Implementation

Implement:

- `professionals` migration;
- `Professional` Eloquent model;
- `Business` ↔ `Professional` relationships;
- nullable `display_name` customer-facing configuration;
- deterministic Lúmina Professional seed data for the expected development workflow;
- relevant automated tests.

Use only the Professional identity and display information required by S02 from `docs/reference-business.md`.

Do not seed Professional scheduling data.

## Acceptance criteria

- a Professional belongs to one Business;
- a Business retrieves its Professionals;
- first and last names are persisted separately;
- `last_name` may contain one or more configured surnames;
- `display_name` may be null;
- a configured `display_name` is persisted;
- names and display names are not unique identifiers;
- multiple Professionals may share the same first name or complete configured name;
- distinct Professionals remain identifiable internally by `id`;
- Lúmina's reference Professionals are seeded correctly;
- reference reseeding does not delete unrelated Professionals.

## Tests

Cover at least:

1. Professional persistence;
2. Business relationship;
3. nullable and configured `display_name`;
4. multiple Professionals sharing a first name;
5. multiple Professionals may share the same complete name and remain distinguishable by `id`;
6. `display_name` does not define Professional identity;
7. reference Professional seed data;
8. repeated reference seeding preserves canonical reference records and unrelated Professionals in the expected development workflow.

Do not implement Task 3 as part of this task.

---

# Task 3 — Professional-Service eligibility

## Goal

Persist authoritative Professional-Service eligibility.

## Implementation

Implement:

- `professional_service` pivot migration;
- unique Professional-Service pair;
- foreign-key integrity;
- Eloquent `belongsToMany` relationships in both directions;
- deterministic reference eligibility configuration;
- relevant automated tests.

Use the canonical Professional-Service eligibility from `docs/reference-business.md`.

The seed should synchronize reference relationships so obsolete reference eligibility does not survive repeated seeding.

Do not introduce scheduling availability.

## Acceptance criteria

- a Professional may provide multiple Services;
- a Service may have multiple eligible Professionals;
- duplicate Professional-Service pairs are prevented;
- deleting either related entity does not leave orphan pivot rows;
- reference eligibility exactly matches the configured Lúmina data;
- repeated reference seeding removes stale eligibility;
- eligibility is independent from Professional availability.

## Tests

Cover at least:

1. relationships work in both directions;
2. multiple Services per Professional;
3. multiple Professionals per Service;
4. duplicate pairs are rejected/prevented;
5. reference eligibility configuration;
6. deterministic reseeding.

Do not implement Task 4 as part of this task.

---

# Task 4 — Service application capabilities

## Goal

Expose FR-02 Service information through reusable application capabilities.

## Implementation

Introduce under `app/Application/Service/`:

- `ListServices`;
- `GetServiceInformation`;
- `ServiceInformation`;
- `ProfessionalSummary`;
- behavioral tests covering FR-02 and S02 acceptance.

Use the `App\Application\Service` namespace and follow the application capability structure established by S01.

Do not add AI tools, HTTP controllers or Business-resolution infrastructure.

## ListServices behavior

Given a Business:

- return only Services belonging to that Business;
- return only active Services;
- return structured Service information;
- order Services deterministically by name;
- exclude inactive Services.

## GetServiceInformation behavior

Given a Business and Service identifier:

Return `ServiceInformation` only when the Service:

- exists;
- belongs to the supplied Business;
- is active.

Return `null` when the Service:

- does not exist;
- is inactive;
- belongs to another Business.

## ServiceInformation behavior

Expose:

- authoritative Service identity and description;
- authoritative duration;
- configured price and currency;
- eligible Professionals belonging to the same Business.

Eligible Professionals must:

- come from configured eligibility;
- belong to the supplied Business;
- expose their internal `id`;
- expose `firstName` and `lastName`;
- expose a customer-facing `displayName`;
- use configured `display_name` for `displayName` when present;
- otherwise derive `displayName` from the configured first and last name;
- be ordered by first name, last name and id;
- not include availability information;
- not expose cross-Business Professionals even if inconsistent pivot data exists.

## Acceptance criteria

- active Service catalogue can be retrieved;
- inactive Services are not exposed;
- Service descriptions remain nullable;
- price and duration come from authoritative configuration;
- zero price remains distinguishable from unavailable price;
- eligible Professionals are determined from configuration;
- customer-facing Professional names respect configured `display_name` and its fallback;
- nonexistent, inactive and other-Business Service references fail safely;
- cross-Business Professionals are not exposed as eligible;
- no conversational inference or scheduling calculation is required.

## Tests

Cover at least:

1. active Service catalogue retrieval;
2. deterministic Service ordering;
3. complete Service information;
4. nullable description/price behavior;
5. free Service behavior;
6. eligible Professional retrieval;
7. configured Professional `display_name`;
8. customer-facing display-name fallback when no alias is configured;
9. deterministic Professional ordering;
10. inactive Service exclusion;
11. nonexistent Service;
12. other-Business Service isolation;
13. cross-Business Professional exclusion;
14. Lúmina reference data exercising the capabilities.

---

# Slice acceptance

S02 is complete when:

- Services are authoritative PostgreSQL Business configuration;
- Service duration and price are structured authoritative values;
- Professionals are persistent Business configuration;
- Professional customer-facing display information is represented explicitly;
- Professional-Service eligibility is persistent configuration;
- active Services can be retrieved deterministically;
- eligible Professionals can be determined without inference;
- customer-facing Professional names can be exposed deterministically;
- invalid, inactive and cross-Business Service references fail safely;
- S02 does not calculate Professional availability;
- reference Business data exercises the implementation;
- all S02 tests and existing relevant backend validation pass.

After Task 4, run the complete backend validation required by the repository and review the implementation against FR-02, BR-01, BR-02 and the applicable architecture constraints.
