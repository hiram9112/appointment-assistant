# S06 — View Appointments

**Status:** Done  
**Depends on:** S05  
**Covers:** FR-05; BR-06; BR-07

## Goal

Allow an identified, registered Customer to retrieve all their Appointments through a reusable, read-only Laravel application capability.

The capability must enforce Customer ownership, return structured Appointment information and remain independent of AI, messaging channels and external integrations.

---

## Design decisions

### Application capability

Introduce a single application capability:

`GetCustomerAppointments`

The capability receives an identified Customer from a trusted application caller and retrieves all Appointments belonging to that Customer.

Do not introduce separate listing, detail retrieval or date-search capabilities.

Do not add Appointment reference filters, date filters, pagination or arbitrary result limits in S06.

A future conversational caller may identify a particular Appointment from the returned collection using its identifier, date, Service or Professional. Resolving conversational references and ambiguities does not belong to S06.

### Customer identity and ownership

Reuse the Customer identity and registration semantics established in S04.

For real Business Customers, identity must be established through the existing trusted identity flow before invoking the capability.

The capability must:

- receive Customer identity from a trusted application caller, never directly from untrusted conversational content;
- reject missing, nonexistent or unregistered Customer identity;
- enforce Customer ownership directly in the PostgreSQL query;
- retrieve no Appointments belonging to other Customers;
- avoid exposing whether another Customer owns a particular Appointment.

A registered Customer is a persisted Customer associated with a supported ChannelIdentity, consistent with S04 and S05.

Distinguish these outcomes:

- identified Customer with Appointments: successful collection;
- identified Customer without Appointments: successful empty collection;
- Customer not identified or not registered: explicit identification failure;
- database or other technical failure: technical failure, not an empty collection.

Follow established Laravel application result conventions. Do not introduce a new authentication mechanism or perform channel identity resolution inside this capability.

The future caller remains responsible for supplying trustworthy Customer identity. The application remains responsible for enforcing Appointment ownership.

### Appointment selection and ordering

Retrieve every Appointment belonging to the identified Customer, regardless of date or lifecycle status.

Include:

- `confirmed`;
- `cancelled`;
- `completed`.

Historical Appointments must not be excluded.

Do not filter by the current active state of the associated Service or Professional. Existing Appointments remain historical records even when related Business configuration changes.

Order results deterministically by:

1. `starts_at` descending;
2. `id` descending.

Return an empty collection when the identified Customer has no Appointments.

### Structured Appointment information

Each result exposes six customer-facing fields:

- `id`;
- `service_name`;
- `professional_name`;
- `starts_at`;
- `ends_at`;
- `status`.

Use authoritative persisted Appointment relationships.

`service_name` comes from the associated Service.

`professional_name` follows the S02 customer-facing display-name convention:

- use the configured Professional `display_name` when present;
- otherwise use the established first-name and last-name fallback.

Appointment timestamps remain authoritative UTC instants in PostgreSQL.

Represent `starts_at` and `ends_at` in the timezone of the Business associated with the Appointment's Service.

The structured result must preserve an unambiguous timezone offset and must not modify persisted timestamps.

Use an explicit read-only result representation consistent with existing application capabilities. Do not expose raw Eloquent models as the customer-facing result contract.

Do not include additional fields such as:

- Customer contact details;
- Service prices;
- scheduling buffers;
- internal scheduling configuration;
- Appointment audit history.

Use appropriate Eloquent relationship loading to avoid N+1 queries when returning multiple Appointments.

### Conversational interpretation boundary

S06 returns the complete authoritative collection. It does not determine which records should appear in a natural-language response.

The future conversational layer will interpret general requests such as "What appointments do I have?" as requests for upcoming confirmed Appointments.

A past Appointment must not be presented as an upcoming Appointment merely because its persisted status is still `confirmed`.

Explicit questions about previous, completed or cancelled Appointments may use the corresponding historical records.

The conversational layer may reuse the retrieved collection during an active Conversation, avoiding unnecessary repeated reads.

However, Appointment-changing operations must independently revalidate current authoritative state and Customer ownership. Previously retrieved conversational data cannot authorize mutations.

Do not implement this conversational behavior, conversation persistence, caching or AI tools in S06.

### Scope boundaries

S06 implements read-only Appointment retrieval.

It does not implement:

- Appointment creation, rescheduling, cancellation or completion;
- Appointment audit writes;
- scheduling availability calculations;
- Customer registration or identity recovery changes;
- HTTP/API endpoints;
- conversational AI or messaging integrations;
- frontend functionality;
- new persistence entities or migrations;
- multi-Business or SaaS infrastructure.

---

# Task 1 — Get Customer Appointments

## Goal

Implement the complete customer-scoped, read-only Appointment retrieval capability with structured results and explicit identity failure handling.

## Implementation

Implement `GetCustomerAppointments` following the Laravel application-layer conventions established by previous slices.

Reuse the existing:

- Customer and ChannelIdentity persistence;
- Appointment model and relationships;
- Service and Professional models;
- Business timezone configuration;
- Professional customer-facing display-name convention.

The capability must:

1. Validate that the supplied Customer represents an existing registered Customer.
2. Return an explicit identification failure when that requirement is not satisfied.
3. Query Appointments through an authoritative `customer_id` restriction.
4. Retrieve all matching lifecycle statuses and historical records.
5. Apply deterministic ordering by `starts_at DESC, id DESC`.
6. Load the required Service, Professional and Business information efficiently.
7. Map each Appointment to the six approved customer-facing fields.
8. Convert start and end instants to the applicable Business timezone.
9. Return the structured collection, including an empty collection when appropriate.

Use Eloquent directly unless the current implementation reveals a concrete need for another abstraction.

Do not duplicate S04 identity-resolution logic, introduce a repository layer or implement conversational Appointment selection.

Technical database failures must propagate as technical failures rather than being converted into an empty result or an identification failure.

## Acceptance criteria

- An identified, registered Customer can retrieve their Appointments.
- A Customer with no Appointments receives a successful empty collection.
- Missing, nonexistent or unregistered Customer identity produces an explicit failure.
- Only Appointments belonging to the identified Customer are retrieved.
- Multiple Appointments are returned without truncation.
- Confirmed, cancelled and completed Appointments are included.
- Past and future Appointments are included.
- Results are ordered by start time descending, with ID descending as the tie-breaker.
- Each Appointment exposes exactly the six approved customer-facing fields.
- Service and Professional names come from authoritative configuration.
- Professional display names follow the existing configured-name and fallback rules.
- Start and end instants are represented in the applicable Business timezone with an unambiguous offset.
- Multiple Appointment retrieval avoids N+1 relationship queries.
- Technical failures are not disguised as empty collections.
- The operation does not modify persistent state.
- No AI, HTTP, messaging or later-slice functionality is introduced.

## Tests

Cover at least:

1. Registered Customer with one Appointment.
2. Registered Customer with multiple Appointments.
3. Registered Customer with no Appointments.
4. Missing or nonexistent Customer.
5. Persisted Customer without a ChannelIdentity.
6. Customer ownership isolation when multiple Customers have Appointments.
7. Appointments with `confirmed`, `cancelled` and `completed` statuses.
8. Historical and future Appointments without automatic filtering.
9. Descending `starts_at` ordering.
10. Deterministic ordering for equal start times using Appointment ID.
11. Complete structured result containing the six approved fields.
12. Correct Service name, including historically persisted Appointments associated with an inactive Service.
13. Configured Professional `display_name`.
14. Professional display-name fallback.
15. UTC-to-Business-timezone conversion, including standard and daylight-saving offsets.
16. Start and end timestamps preserving their original instants.
17. Exclusion of unapproved customer-facing fields.
18. Efficient relationship loading for multiple Appointments.
19. Technical persistence failure remains distinguishable from an empty result.
20. Read-only behavior.
21. Lúmina reference configuration exercising the capability.

Run the relevant S04 and S05 tests to verify that identity, Appointment persistence and ownership-related behavior remain intact.

---

# Slice acceptance

S06 is complete when:

- `GetCustomerAppointments` provides a single reusable, read-only Laravel capability;
- identified registered Customers can retrieve all their own Appointments;
- Customer ownership is enforced by PostgreSQL query restrictions;
- invalid Customer identity cannot expose Appointment information;
- empty results, identity failures and technical failures are distinguishable;
- returned Appointment facts are complete, structured and deterministically ordered;
- timestamps and Professional display names follow existing Business configuration;
- the implementation does not introduce conversational behavior or unrelated infrastructure;
- all S06 tests and relevant existing backend validation pass.

After Task 1, run the complete backend validation required by the repository and review the implementation against FR-05, BR-06, BR-07, the applicable architecture constraints and established S04/S05 behavior.
