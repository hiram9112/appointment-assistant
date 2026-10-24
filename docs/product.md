# Product Specification

## 1. Product overview

Appointment Assistant is a conversational system that allows service businesses
to handle customer enquiries and manage appointments through natural language.

The system is designed around businesses where one or more Professionals provide
Services according to their own availability.

The V1 uses a fictional non-medical advanced beauty center as its reference
business, but the product domain must not depend on industry-specific concepts.

Customer interaction channels are delivery mechanisms and must not define the
core appointment domain.

The V1 focuses on text-based conversational interaction, but the product should
allow additional interaction channels, such as voice, to reuse the same business
capabilities without duplicating scheduling logic.

## 2. Reference scenario

The V1 simulates a non-medical advanced beauty center with:

- multiple Professionals;
- multiple Services;
- different Service durations and prices;
- individual Professional availability;
- business opening hours;
- customer appointments;
- conversational customer support;
- synchronization with an operational business schedule;
- notifications to Professionals when their appointments change.

The reference business is used to develop, test and demonstrate the product.

Business-specific concepts must not be embedded into the core domain when a
generic scheduling concept can represent the same behavior.

## 3. V1 objectives

A Contact or Customer must be able to:

- ask questions about the Business;
- ask about Services, prices and durations;
- ask about Professionals and availability;
- search for available Appointment slots.

A registered Customer must also be able to:

- create an Appointment;
- view an existing Appointment;
- reschedule an Appointment;
- cancel an Appointment.

The V1 must also:

- maintain coherent multi-turn conversations;
- preserve Appointment state independently from the conversational layer;
- synchronize Appointments with the operational schedule used by the Business;
- notify affected Professionals of relevant Appointment changes;
- remind Customers of upcoming Appointments according to Business configuration;
- preserve an audit history of Appointment-changing actions;
- expose the customer-facing conversational experience through supported text-based channels;
- provide a public web experience that presents Appointment Assistant, its purpose and core capabilities, and demonstrates the real application through a conversational interface and a read-only Appointment calendar reflecting Appointment creation, rescheduling and cancellation.
- require Customer registration before creating an Appointment;
- maintain persistent Customer identity across Appointments and Conversations;
- associate each registered Customer with a verified mobile phone and their
  WhatsApp channel identity.

## 4. V1 scope boundaries

The V1 focuses on delivering the complete customer-facing conversational Appointment flow, the operational integrations required to support it and
the public demonstration experience.

The following capabilities are intentionally outside the V1 scope:

- internal Business administration interface;
- manual Appointment management through an administration panel;
- Business configuration through a graphical interface;
- voice interaction;
- multi-Business or SaaS capabilities;
- payments;
- advanced analytics;
- retrieval-augmented knowledge capabilities;
- Customer passwords, login credentials or self-service account areas;
- CRM, loyalty and Customer retention features;

Business and scheduling configuration required by the V1 may initially be
provided as application or deployment data rather than through an administration
interface.

These exclusions must not prevent the product from being extended with these
capabilities in later versions.

## 5. Core actors

### Contact

Person interacting with the Business through a supported customer-facing channel
without yet having a persistent Customer registration.

A Contact may ask for Business information, Services, prices, Professionals and
Appointment availability.

A Contact must complete Customer registration before an Appointment can be
created.

### Customer

Person who has completed the required V1 registration and has a persistent
Customer record in the application.

A Customer is identified by:

- name;
- verified mobile phone;
- associated WhatsApp channel identity.

A Customer may create, view, reschedule and cancel their Appointments.

Customer identity persists independently from individual Conversations and
Appointments.

### Professional

Person providing one or more Services and having their own availability.

### Business

Organization offering Services through its Professionals.

## 6. V1 Business configuration model

Appointment Assistant must represent the common operational configuration required
to manage Appointments without embedding Business-specific values in application
logic.

The V1 Business configuration includes at least the following concepts.

### Business information

A Business has configurable customer-facing information, including:

- name;
- description when applicable;
- location and contact information when applicable;
- timezone;
- other operational information required by the Customer-facing experience.

### Operating schedule

A Business has configurable scheduling information, including:

- normal operating hours;
- days when the Business is closed;
- temporary Business-level scheduling exceptions when applicable.

### Services

Each Service has configurable information including:

- name;
- description when applicable;
- duration;
- price when applicable;
- active status.

### Professionals

Each Professional has configurable information including:

- identifying and display information;
- Services they are eligible to provide;
- recurring availability;
- breaks and unavailable periods;
- temporary availability exceptions and blocks.

### Scheduling policies

Business-level scheduling policies may include:

- Professional selection behavior;
- cancellation rules, including any minimum notice period;
- whether Customer reminders are enabled;
- reminder timing when reminders are enabled.

Business configuration values are application data and must not be embedded in
conversational prompts or application source code.

The mechanism used to manage this configuration is outside the V1 scope. During
V1, configuration may be initialized through application or deployment data.

## 7. Conversational interaction principles

The system is designed for natural-language interaction. Customers must not be
required to use predefined commands, exact Service names or rigid sentence
structures.

The assistant should be able to understand common conversational variations,
including:

- colloquial expressions;
- incomplete sentences;
- context-dependent references;
- relative dates and times;
- different ways of referring to the same Service or action;
- regional, informal or alternative ways of expressing the same intention.

The system must use the active Conversation context when interpreting a message.

However, the assistant must not assume information that cannot be inferred with
reasonable confidence.

When the Customer's intention or required information is ambiguous, the assistant
must ask a clarification question before continuing with the affected operation.

Clarification should be helpful and move the Conversation forward whenever
possible.

For example:

Customer:

> "Tomorrow afternoon."

Assistant:

> "Sure. Are you looking to book an appointment tomorrow afternoon? If so, which
> service would you like?"

The system may use a probable interpretation to formulate a clarification
question, but it must not perform an Appointment-changing action based on an
uncertain interpretation.

When enough context exists to safely infer the Customer's meaning, the assistant
should avoid unnecessarily asking for information that has already been provided.

## 8. Functional requirements

### FR-01 — Provide Business information

**Description**

A Contact or Customer can ask for information about the Business.

**Required information**

- Business information relevant to the Customer's request.

**Expected behavior**

The system provides accurate information configured for the Business, including,
when applicable:

- opening hours;
- location and contact information;
- accepted payment methods;
- booking and cancellation policies;
- general Business information.

The system must not provide Business information that is not available or
configured.

**Alternative cases**

- the requested information is not available;
- the question is outside the scope of the Business.

**Relevant errors**

- Business information cannot be retrieved;
- temporary system failure.

### FR-02 — Provide Service information

**Description**

A Contact or Customer can ask about the Services offered by the Business.

**Required information**

- Service or Service-related request.

**Expected behavior**

The system can provide information including:

- available Services;
- Service descriptions;
- prices;
- durations;
- Professionals capable of providing each Service.

**Alternative cases**

- the requested Service does not exist;
- multiple Services could satisfy the request;
- the Customer asks for general Service options rather than a specific Service.

**Relevant errors**

- Service information cannot be retrieved;
- temporary system failure.

### FR-03 — Check Appointment availability

**Description**

A Contact or Customer can request available Appointment slots.

**Required information**

- Service;
- date or date range;
- optional Professional preference;
- optional time preference.

**Expected behavior**

When the Customer requests a specific Professional and that Professional has no
valid availability for the requested period, the system must inform the Customer
and, when possible, offer availability from other Professionals eligible to
provide the requested Service.

The system must not silently replace a Professional explicitly requested by the
Customer.

The system returns valid Appointment slots according to:

- Service duration;
- Professional eligibility for the requested Service;
- Professional availability;
- Business scheduling rules;
- existing Appointments;
- blocked or unavailable periods.

The system may propose suitable nearby alternatives when the exact requested
availability cannot be satisfied.

**Alternative cases**

- the Service has not yet been specified;
- the requested Professional cannot provide the Service;
- the requested Professional is unavailable;
- no slots are available for the requested period;
- another eligible Professional is available;
- nearby times are available.

**Relevant errors**

- invalid or unknown Service;
- invalid date or time;
- availability data cannot be retrieved;
- temporary system failure.

### FR-04 — Create an Appointment

**Description**

The Customer can create an Appointment.

**Required information**

- Customer;
- Service;
- Appointment date and time;
- selected Professional;
- any additional Customer information required by the Business.

**Expected behavior**

Before creating the Appointment, the system must verify that:

- the Service exists;
- the selected Professional can provide the Service;
- the requested slot is still available;
- the Appointment satisfies the applicable scheduling rules.

The system must revalidate availability when the Appointment is created rather
than relying only on availability previously shown to the Customer.

If validation succeeds, the Appointment is created and the Customer receives a
clear confirmation containing the relevant Appointment details.

A Professional must never have overlapping Appointments.

**Alternative cases**

- required Appointment information is missing;
- a previously offered slot is no longer available;
- the requested Professional is unavailable;
- another eligible Professional is available;
- an alternative slot can be proposed.

**Relevant errors**

- concurrent booking conflict;
- Appointment creation fails;
- persistence failure;
- temporary system failure.

### FR-05 — View existing Appointments

**Description**

The Customer can request information about their existing Appointments.

**Required information**

- Customer;
- optional Appointment reference.

**Expected behavior**

The system returns the relevant Appointment information, including:

- Service;
- Professional;
- date and time;
- Appointment status.

The system must only expose Appointments belonging to the identified Customer.

**Alternative cases**

- the Customer has no relevant Appointments;
- the Customer has multiple Appointments;
- the requested Appointment cannot be uniquely identified.

**Relevant errors**

- Customer cannot be identified;
- Appointment information cannot be retrieved;
- temporary system failure.

### FR-06 — Reschedule an Appointment

**Description**

The Customer can move an existing Appointment to another valid slot.

**Required information**

- Customer;
- Appointment to modify;
- requested date or time;
- optional Professional preference.

**Expected behavior**

The system identifies the existing Appointment and determines valid alternatives
according to the same availability and scheduling rules used for new
Appointments.

Before applying the change, the system must verify that the new slot is still
available and satisfies all applicable scheduling rules.

The existing Appointment must remain valid until the rescheduling operation has
successfully completed.

Once the operation succeeds, the Customer receives confirmation containing the
updated Appointment details.

**Alternative cases**

- the Customer has multiple Appointments;
- the Appointment cannot be uniquely identified;
- the requested new slot is unavailable;
- the current Professional is unavailable;
- another eligible Professional is available;
- no suitable alternatives are available.

**Relevant errors**

- concurrent scheduling conflict;
- Appointment cannot be modified;
- persistence failure;
- temporary system failure.

### FR-07 — Cancel an Appointment

**Description**

The Customer can cancel an existing Appointment.

**Required information**

- Customer;
- Appointment to cancel.

**Expected behavior**

The system identifies the Appointment, verifies that cancellation is permitted
under the applicable Business rules and cancels it.

The Customer receives explicit confirmation only after the cancellation has been
successfully completed.

**Alternative cases**

- the Customer has multiple Appointments;
- the Appointment cannot be uniquely identified;
- the Appointment has already been cancelled;
- the Appointment has already occurred;
- cancellation is restricted by a Business rule.

**Relevant errors**

- Appointment cannot be cancelled;
- persistence failure;
- temporary system failure.

### FR-08 — Maintain conversational continuity

**Description**

The system must maintain the information required to support coherent multi-turn
interactions during an active Conversation.

**Required information**

- active Conversation;
- relevant previously established conversational information.

**Expected behavior**

The system preserves relevant conversational state so that Customers do not need
to repeat information that has already been established.

A Conversation may move between:

- Business enquiries;
- Service enquiries;
- availability enquiries;
- Appointment creation;
- Appointment modification;
- Appointment cancellation.

Relevant information must remain available while it continues to apply to the
active interaction.

Information explicitly changed by the Customer must replace the previous value
for the affected interaction.

Conversation history must be stored independently from the active conversational
state.

After a configurable period of inactivity, a new interaction may be treated as a
new Conversation without losing persisted Customer or Appointment data.

Previous Conversations must not be assumed to represent the Customer's current
intent.

**Alternative cases**

- the Customer changes topic;
- previously established information is replaced;
- previous information is no longer relevant;
- a new Conversation begins after a period of inactivity.

**Relevant errors**

- Conversation state cannot be retrieved;
- Conversation state is inconsistent;
- temporary system failure.

### FR-09 — Synchronize Appointments with the Business schedule

**Description**

Appointments managed by the system must be reflected in the operational schedule
used by the Business.

**Required information**

- Appointment;
- assigned Professional;
- Appointment start and end time.

**Expected behavior**

When an Appointment is created, rescheduled or cancelled, the corresponding
entry in the Business schedule must be created, updated or cancelled
accordingly.

The schedule entry must contain enough information for the Business to identify:

- Customer;
- Service;
- Professional;
- start time;
- end time;
- Appointment status when relevant.

Appointments belonging to different Professionals must be distinguishable so
that the Business can view individual Professional schedules or a combined
schedule.

Appointment Assistant remains the authoritative source for Appointments.

Failure to synchronize the Business operational schedule must not invalidate or
remove an Appointment that has already been successfully persisted by the system.

**Alternative cases**

- an Appointment is created;
- an Appointment is rescheduled;
- an Appointment is cancelled;
- schedule synchronization is temporarily unavailable.

**Relevant errors**

- Business schedule is temporarily unavailable;
- schedule synchronization fails;
- a schedule entry cannot be created or updated.

### FR-10 — Notify Professionals of Appointment changes

**Description**

The relevant Professionals must be notified when an Appointment change
affects their schedules.

**Required information**

- Appointment;
- affected Professional or Professionals;
- type of Appointment change.

**Expected behavior**

The notification provides enough information for the Professional to understand
the schedule change, including:

- Service;
- date and time;
- type of change.

When an Appointment is reassigned from one Professional to another,
both the previous and the newly assigned Professional are affected.

Notification delivery must be independent from the validity of the Appointment.

A temporary notification failure must not undo a successfully created,
rescheduled or cancelled Appointment.

**Alternative cases**

- Appointment created;
- Appointment rescheduled;
- Appointment cancelled;
- notification delivery is temporarily unavailable.

**Relevant errors**

- notification provider is unavailable;
- notification delivery fails.

### FR-11 — Maintain an Appointment audit trail

**Description**

The system must maintain a persistent history of actions that change Appointment
state.

**Expected behavior**

Every Appointment creation, rescheduling, Professional reassignment or
cancellation must produce an audit record.

Each audit record must identify, when applicable:

- the affected Appointment;
- the action performed;
- when the action occurred;
- who or what performed the action;
- the source through which the action was performed;
- the relevant state before the change;
- the relevant state after the change.

The audit model must support different action sources so that future interaction
channels or administrative operations can be distinguished without changing the
Appointment domain.

Audit history must remain available when an Appointment is cancelled.

Audit records must not be silently modified or removed through normal Appointment
operations.

**Alternative cases**

- an Appointment has only been created and never modified;
- an Appointment has been modified multiple times;
- different system components have modified the same Appointment.

**Relevant errors**

- audit recording fails;
- temporary system failure.

### FR-12 — Remind Customers of upcoming Appointments

**Description**

The Customer must receive a reminder before an upcoming confirmed Appointment
according to the reminder policy configured by the Business.

**Required information**

- Appointment;
- Customer;
- assigned Professional;
- Service;
- configured reminder timing.

**Expected behavior**

The system schedules a reminder for each eligible confirmed Appointment.

The reminder must provide enough information for the Customer to identify the
Appointment, including:

- Service;
- Professional;
- Appointment date;
- Appointment time.

The reminder timing must be configurable by the Business.

If an Appointment is rescheduled, the previously scheduled reminder must no
longer be sent using the old Appointment time and a new reminder must be scheduled
according to the updated Appointment.

If an Appointment is cancelled before the reminder is sent, the reminder must not
be delivered.

A temporary notification failure must not affect the validity of the Appointment.

**Alternative cases**

- the Appointment is rescheduled before the reminder is sent;
- the Appointment is cancelled before the reminder is sent;
- the configured reminder time has already passed when the Appointment is
  created;
- reminder delivery is temporarily unavailable.

**Relevant errors**

- reminder cannot be scheduled;
- notification provider is unavailable;
- reminder delivery fails.

### FR-13 — Provide public product demonstration

**Description**

The V1 provides a public web experience that presents Appointment Assistant
and demonstrates its core Appointment capabilities.

**Expected behavior**

The web experience must:

- explain the purpose and core capabilities of Appointment Assistant;
- provide a conversational interface using the real application backend;
- provide a read-only visual calendar containing bounded demo Appointment data;
- reflect Appointment creation, rescheduling and cancellation performed
  through the conversational demo;
- not allow direct Appointment modification through the calendar;
- not expose private Customer information through the public calendar.

The public demo uses isolated demo identities and demo Customer data.

Appointment operations performed through the public demo must not require real
Business Customer registration and must not create, expose or modify real
Business Customer records.

### FR-14 — Register a Customer

**Description**

A Contact using the real Business interaction channel must complete Customer
registration before a real Business Appointment can be created.

**Required information**

- name;
- mobile phone;
- WhatsApp channel identity.

**Expected behavior**

When an unregistered Contact attempts to create an Appointment, the system must
start the Customer registration flow before completing the booking.

The system must:

- collect the Contact's name;
- collect their mobile phone;
- verify that the mobile phone belongs to the Contact;
- create a persistent Customer record;
- associate the verified mobile phone with that Customer;
- associate the active WhatsApp channel identity with that Customer.

Once registration succeeds, the Appointment flow may continue without requiring
the Customer to restart the Conversation or repeat information already provided.

When the active WhatsApp identity already belongs to an existing Customer, the
system must reuse that Customer and must not require registration again.

Customer registration does not require a password, email address, login account
or self-service Customer area.

**Alternative cases**

- the WhatsApp identity already belongs to an existing Customer;
- the provided mobile phone is already associated with an existing Customer;
- the Contact abandons registration;
- mobile verification cannot be completed.

**Relevant errors**

- mobile verification fails;
- Customer registration cannot be completed;
- Customer data cannot be persisted;
- temporary system failure.

## 9. Product business rules

### BR-01 — Professional-Service eligibility

A Professional may provide one or more Services.

Not every Professional is required to provide every Service offered by the
Business.

An Appointment may only be assigned to a Professional who is configured as
eligible to provide the requested Service.

Professional-Service eligibility must be defined by Business configuration and
must not be inferred by the conversational assistant.

### BR-02 — Service duration

Each Service must have a defined duration used when calculating Appointment
availability.

The duration of an Appointment is determined by the selected Service and must not
be estimated by the conversational assistant.

A valid Appointment slot must provide enough continuous Professional availability
to accommodate the complete Service duration.

### BR-03 — Professional availability

The Business must define its normal operating hours.

Each Professional must have defined availability within the Business operating
hours.

A Professional may follow the complete Business schedule or have a more
restricted individual schedule, for example because they work part-time or only
on specific days.

Professional availability may include:

- recurring working periods;
- breaks or unavailable periods;
- temporary exceptions;
- full-day or partial-day blocks.

A Professional may only receive an Appointment when the complete duration of the
requested Service fits within a continuous available period.

Temporary exceptions and blocked periods take precedence over recurring
availability.

Professional availability must not extend outside the Business operating hours
unless an explicit Business exception allows it.

Availability must be defined by Business configuration and must not be inferred
or modified by the conversational assistant.

### BR-04 — Appointment slot validity

An Appointment slot is valid only when all applicable scheduling conditions are
satisfied.

At minimum, a valid slot must satisfy the following conditions:

- the requested Service exists and is active;
- the selected Professional is eligible to provide the requested Service;
- the complete Service duration fits within the Professional's effective availability;
- the Appointment does not overlap an existing active Appointment assigned to the same Professional;
- the slot does not overlap a blocked or unavailable period;
- the slot satisfies the applicable Business scheduling policies.

Availability must be evaluated using the current system state at the time the
slot is requested.

A slot previously presented to a Customer must not be considered guaranteed until
the Appointment is successfully created.

The conversational assistant must not determine slot validity independently. It
must rely on the scheduling capabilities provided by the application.

### BR-05 — Appointment overlap and concurrency

A Professional must never have overlapping active Appointments.

When an Appointment is created or rescheduled, the system must validate the target
slot against the current scheduling state immediately before applying the change.

If multiple Customers attempt to reserve the same Professional and overlapping
time period concurrently, at most one operation may succeed.

A previously calculated or displayed slot does not reserve or lock that time for
the Customer.

If the requested slot becomes unavailable before the Appointment is successfully
created or rescheduled, the operation must fail safely and the Customer must be
offered valid alternatives when possible.

Concurrency protection is a system responsibility and must not depend on the
conversational assistant.

### BR-06 — Appointment lifecycle

An Appointment must have an explicit lifecycle state.

At minimum, the system must support:

- confirmed;
- cancelled;
- completed.

Only confirmed Appointments occupy scheduling availability.

A cancelled Appointment must no longer block the Professional's availability.

A completed Appointment must remain part of the Business history but must not be
modifiable through normal rescheduling or cancellation operations.

An Appointment may only transition between states allowed by the product rules.

Cancellation must preserve the Appointment record and its audit history rather
than physically deleting it.

Appointment state changes must be performed by the application and must not be
inferred or applied directly by the conversational assistant.

### BR-07 — Customer identity, ownership and access

A real Business Appointment may only belong to a registered Customer.

Public demo Appointments use isolated demo Customer identities and must not be
associated with real Business Customers.

Each registered Customer must have a persistent application identity associated
with:

- their name;
- a verified mobile phone;
- their WhatsApp channel identity.

The verified mobile phone and WhatsApp channel identity must resolve to the same
Customer record.

A WhatsApp channel identity already associated with a Customer must resolve that
Customer in subsequent Conversations.

A verified mobile phone already associated with a Customer must not result in a
duplicate Customer record.

An Appointment must be associated with the Customer for whom it was created.

A Customer may only view, reschedule or cancel Appointments that belong to them.

The system must establish sufficient Customer identity before exposing or
modifying Appointment information.

If the system cannot identify the Customer with sufficient confidence, it must
not expose or modify Appointment data.

Customer identity, Appointment ownership and access control must be enforced by
the application and must not depend on the conversational assistant.

### BR-08 — Rescheduling consistency

Rescheduling an Appointment must preserve consistency between the existing
Appointment and the requested replacement slot.

The existing Appointment must remain valid until the new slot has been
successfully validated and the rescheduling operation has completed.

A rescheduling operation must not temporarily release the original slot before
the replacement slot is guaranteed.

If the requested replacement slot becomes unavailable or any part of the
rescheduling operation fails, the original Appointment must remain unchanged.

A successful rescheduling must:

- preserve the identity of the Appointment;
- update the assigned Professional when explicitly changed and valid;
- update the start and end time;
- preserve the Customer association;
- produce the corresponding audit record;
- trigger the applicable schedule synchronization and Professional notification.

Rescheduling consistency must be enforced by the application and must not depend
on the conversational assistant.

### BR-09 — Cancellation policy

The Business may define rules that determine when a confirmed Appointment can be
cancelled.

Cancellation rules may include:

- a minimum notice period before the Appointment starts;
- cases where cancellation is no longer permitted through the Customer-facing conversational flow;
- Business-specific exceptions when applicable.

If cancellation is permitted, the Appointment must transition to the cancelled
state and must immediately stop occupying Professional availability.

If cancellation is not permitted under the configured policy, the system must not
cancel the Appointment and must clearly inform the Customer that the request
cannot be completed under the current Business rules.

Cancelling an Appointment must preserve:

- the Appointment identity;
- the Customer association;
- the previous Appointment information;
- the Appointment audit history.

Cancellation must trigger the applicable Business schedule synchronization and
Professional notification.

Cancellation rules must be defined by Business configuration and enforced by the
application, not by the conversational assistant.

### BR-10 — Appointment confirmation and side effects

An Appointment operation is considered successful when the authoritative
Appointment state has been successfully persisted by the application.

External side effects, including Business schedule synchronization and
Professional notifications, must not determine whether the Appointment itself is
valid.

After a successful Appointment creation, rescheduling or cancellation:

- the authoritative Appointment state must be persisted;
- the corresponding audit record must be created;
- required external side effects must be scheduled for execution;
- failures in external integrations must be recoverable without reverting the successful Appointment operation.

If an external side effect fails, the system must preserve enough state to retry
or reconcile the failed operation.

The Customer must not be told that an Appointment operation succeeded before the
authoritative Appointment state has been successfully persisted.

The conversational assistant must report the result provided by the application
and must not infer success from an incomplete or failed external operation.

### BR-11 — Appointment reminders

Upcoming confirmed Appointments may require a Customer reminder according to the
Business scheduling configuration.

Only confirmed future Appointments are eligible for reminders.

A reminder must reflect the current Appointment state at the time it is sent,
including:

- Service;
- Professional;
- date;
- time.

If an Appointment is rescheduled, any pending reminder associated with the
previous schedule must no longer be delivered.

If an Appointment is cancelled, any pending reminder must no longer be
delivered.

Reminder delivery is independent from Appointment validity.

Failure to deliver a reminder must not modify or invalidate the Appointment.

### BR-12 — Business schedule consistency

Appointment Assistant must maintain the authoritative state of every Appointment.

Appointment state may be changed through different authorized application
interfaces, including the conversational assistant and future administrative
interfaces.

All authorized interfaces must operate on the same Appointment state and must
apply the same Business rules and validation.

The Business operational schedule must reflect the current authoritative state of
Appointments managed by the system.

When an Appointment is created, rescheduled, reassigned or cancelled, its
corresponding schedule representation must eventually reflect the same state.

Schedule synchronization must not create duplicate representations of the same
Appointment.

If schedule synchronization fails, the authoritative Appointment state must
remain unchanged and the synchronization failure must remain recoverable.

A later synchronization attempt must be able to reconcile the Business schedule
with the current authoritative Appointment state.

### BR-13 — Professional selection

An Appointment must be assigned to one Professional who is eligible to provide
the requested Service.

When the Customer explicitly requests a Professional, availability must first be
searched for that Professional.

The requested Professional may only be selected if they:

- are eligible to provide the requested Service;
- are available for the complete Appointment duration;
- satisfy all applicable scheduling rules.

If the requested Professional has no valid availability, the system must inform
the Customer and, when possible, offer available eligible Professionals as
alternatives.

The system must not silently substitute another Professional.

When the Customer does not request a specific Professional, the system must
present valid availability from eligible Professionals and allow the Customer
to choose.

The system must not automatically prioritize or assign one eligible Professional
over another.

Professional eligibility and availability must be determined using the current
Business configuration and scheduling state.

### BR-14 — Appointment audit integrity

Every operation that changes Appointment state must produce a persistent audit
record.

At minimum, the audit history must record Appointment:

- creation;
- rescheduling;
- Professional reassignment;
- cancellation.

Each audit record must preserve enough information to determine:

- the affected Appointment;
- the action performed;
- when the action occurred;
- the actor or system component responsible for the action;
- the interface or source through which the action was performed;
- the relevant Appointment state before the change;
- the relevant Appointment state after the change.

Audit records must be append-only through normal application operations.

A later Appointment change must create a new audit record rather than replacing
or rewriting previous history.

Audit history must remain associated with the Appointment throughout its
lifecycle, including after cancellation or completion.

All authorized interfaces that can modify Appointment state must participate in
the same audit mechanism.

## 10. Planned extensions

### Business administration

The first planned extension after the V1 will provide an internal administration
interface for managing the Business without requiring source code changes.

The administration interface is expected to support:

- viewing the Business Appointment schedule;
- manually creating, rescheduling and cancelling Appointments;
- managing Professionals;
- managing Services, prices and durations;
- managing Professional-Service eligibility;
- managing recurring Professional availability;
- managing temporary availability exceptions and blocked periods;
- managing customer-facing Business information;
- inspecting Customer Conversations;
- inspecting Appointment audit history.
- managing Business operating hours and Business-level schedule exceptions;
- managing cancellation and rescheduling policies;
- managing Customer reminder configuration and timing;
- searching Customers and viewing their Appointment history;

Administrative Appointment operations must reuse the same scheduling rules and
application capabilities used by the conversational assistant.

### Voice interaction

A later product extension will allow Customers to access the same conversational
and Appointment capabilities through a voice channel.

Voice interaction must reuse the existing application and domain capabilities
rather than introducing separate scheduling logic.

Voice interaction is not part of the V1 acceptance criteria.

### Future product evolution

Additional capabilities may be introduced when required by real Business needs,
including:

- multiple Businesses and SaaS operation;
- multiple locations;
- payments;
- advanced reporting and analytics;
- additional messaging channels;
- retrieval-augmented knowledge capabilities for Customer or Professional-facing assistants;
- additional external integrations.
- richer Customer profiles and Customer self-service capabilities;
- Customer loyalty, discounts and retention capabilities;

These future possibilities must not increase the scope of the V1 unless required
to preserve a clear extension path.
