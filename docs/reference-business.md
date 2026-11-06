# Reference Business — Lúmina Estética Avanzada

## 1. Purpose

This document defines the reference Business used to develop, test and demonstrate Appointment Assistant V1.

The reference Business provides concrete configuration and demo data for the generic product model defined in `product.md`.

It does not define additional product requirements and must not introduce Business-specific logic into the application core.

Changing this reference Business should primarily require replacing configuration and seed data rather than changing scheduling or conversational logic.

---

## 2. Business profile

**Name:** Lúmina Estética Avanzada

**Business type:** Non-medical advanced beauty center

**Location:** Zaragoza, Spain

**Timezone:** `Europe/Madrid`

**Description:**

Lúmina Estética Avanzada is an independent beauty center offering facial treatments, body treatments, laser hair removal and wellness services.

The center operates from a single location with multiple Professionals and uses appointments as its primary service model.

WhatsApp is its main conversational Customer channel.

The Business does not provide medical or clinical treatments.

---

## 3. Public Business information

**Address:**  
Calle Lúmina 18  
50005 Zaragoza, Spain

The address is fictional and exists only for the demonstration environment.

**Phone / WhatsApp:**  
+34 600 000 000

**Email:**  
hola@lumina-demo.test

**Accepted payment methods:**

- card;
- cash.

Appointments are recommended for all Services.

Customers should arrive approximately 5 minutes before their Appointment.

---

## 4. Business operating schedule

| Day       | Opening hours |
| --------- | ------------- |
| Monday    | 09:00–20:00   |
| Tuesday   | 09:00–20:00   |
| Wednesday | 09:00–20:00   |
| Thursday  | 09:00–20:00   |
| Friday    | 09:00–20:00   |
| Saturday  | 09:00–14:00   |
| Sunday    | Closed        |

Business opening hours define the maximum scheduling window.

A Professional may only receive Appointments during their own availability within these Business hours.

---

## 5. Services

| Service                        | Duration | Price | Buffer before | Buffer after |
| ------------------------------ | -------: | ----: | ------------: | -----------: |
| Initial beauty consultation    |   30 min |    €0 |         0 min |        0 min |
| Deep facial cleansing          |   60 min |   €55 |         5 min |       10 min |
| Hydrating facial treatment     |   60 min |   €65 |         5 min |       10 min |
| Premium facial treatment       |   90 min |   €85 |        10 min |       10 min |
| Laser hair removal — underarms |   20 min |   €25 |         5 min |        5 min |
| Laser hair removal — full legs |   45 min |   €65 |         5 min |       10 min |
| Firming body treatment         |   60 min |   €70 |         5 min |       10 min |
| Wellness massage               |   60 min |   €50 |         5 min |       10 min |

All Services are active in the V1 reference configuration.

Service duration, price and scheduling buffers are Business configuration data and must not be embedded in application logic.

---

## 6. Professionals and Service eligibility

### Laura Martín

Can provide:

- Initial beauty consultation;
- Deep facial cleansing;
- Hydrating facial treatment;
- Premium facial treatment.

### Marta Ruiz

Can provide:

- Initial beauty consultation;
- Laser hair removal — underarms;
- Laser hair removal — full legs.

### Claudia Pérez

Can provide:

- Initial beauty consultation;
- Firming body treatment;
- Wellness massage.

### Sara Gómez

Can provide:

- Initial beauty consultation;
- Deep facial cleansing;
- Hydrating facial treatment;
- Laser hair removal — underarms;
- Firming body treatment.

Service eligibility determines which Professionals may be offered for a particular Service.

No additional Professional specialty classification is required for V1.

---

## 7. Professional recurring availability

### Laura Martín

**Monday–Friday:** 09:00–17:00  
**Break:** 13:30–14:30

Effective working time: 7 hours per day.

### Marta Ruiz

**Monday–Thursday:** 11:00–20:00  
**Break:** 15:00–16:00

Effective working time: 8 hours per day.

**Friday:** 09:00–17:00  
**Break:** 15:00–16:00

Effective working time: 7 hours.

### Claudia Pérez

**Tuesday–Friday:** 10:00–19:00  
**Break:** 14:00–15:00

Effective working time: 8 hours per day.

**Saturday:** 09:00–14:00

Effective working time: 5 hours.

### Sara Gómez

**Monday–Wednesday:** 12:00–20:00  
**Break:** 14:00–15:00

Effective working time: 7 hours per day.

**Thursday–Friday:** 09:00–17:00  
**Break:** 14:00–15:00

Effective working time: 7 hours per day.

No Professional works more than 8 effective hours in one day.

---

## 8. Scheduling policies

### Professional selection

Customers may request a specific Professional.

When the Customer does not request a specific Professional, the system presents eligible Professionals with valid availability and allows the Customer to choose.

The Business does not automatically prioritize one Professional over another.

### Appointment availability

A valid Appointment requires:

- the Business to be open;
- the selected Professional to be available;
- the Professional to be eligible for the selected Service;
- the complete scheduling occupancy, including configured buffers, to fit inside the available period;
- no conflicting Appointment to exist.

### Cancellation and rescheduling

The recommended cancellation notice is **24 hours**, and the independent recommended rescheduling notice is also **24 hours**. Both are advisory in V1: otherwise valid cancellations and rescheduling remain permitted with less notice.

A successful operation performed less than 24 hours before the original Appointment start generates a late-notice indication in its AppointmentAudit. An operation at least 24 hours in advance generates no late-notice incident; a failed operation generates none.

No deposits, advance payments or financial penalties apply in V1.

### Customer reminders

Customer reminders are enabled.

A reminder is sent **24 hours before the Appointment**.

---

## 9. Availability exceptions

The reference dataset must contain temporary exceptions in addition to recurring schedules so that availability calculations are not based exclusively on weekly patterns.

For the reference demo week:

### Laura Martín

**Tuesday, 10 November 2026**

Unavailable from:

`09:00–12:00`

Reason:

Personal block.

### Marta Ruiz

**Thursday, 12 November 2026**

Unavailable from:

`17:00–20:00`

Reason:

Professional unavailable.

### Claudia Pérez

**Friday, 13 November 2026**

Unavailable for the entire day.

### Business-level exception

**Wednesday, 11 November 2026**

The Business closes exceptionally at:

`18:00`

Scheduling occupancy extending after 18:00 is not valid on that date.

These exceptions are reference data and do not represent special-case application logic.

---

## 10. Customer-facing information and FAQs

The following information may be provided conversationally to Customers.

### Where is Lúmina located?

Lúmina Estética Avanzada is located at Calle Lúmina 18, 50005 Zaragoza.

The address is fictional and used only by the demo environment.

### What are the opening hours?

Monday to Friday:

`09:00–20:00`

Saturday:

`09:00–14:00`

Sunday:

Closed.

### Do I need an Appointment?

Appointments are recommended for all Services.

### Can I choose the Professional?

Yes.

The Customer may request a specific Professional when booking.

### What if I do not know which Professional to choose?

The assistant may show the Professionals who can provide the requested Service and have valid availability.

The Customer chooses among the available options.

### Is the initial consultation free?

Yes.

The Initial beauty consultation lasts 30 minutes and costs €0.

### When can I cancel or reschedule?

Please give at least 24 hours' notice when cancelling or rescheduling. Otherwise valid changes are still allowed with less notice.

### Will I receive a reminder?

Yes.

The Business sends a reminder 24 hours before the Appointment.

### How early should I arrive?

Customers are encouraged to arrive approximately 5 minutes before their Appointment.

### What happens if I am late?

The Customer should contact the center.

The assistant must not automatically extend the Appointment or move subsequent Appointments.

### Which payment methods are accepted?

The center accepts card and cash payments.

### Are these medical treatments?

No.

Lúmina Estética Avanzada is a non-medical beauty center.

The assistant must not provide medical diagnosis, medical advice or clinical recommendations.

---

## 11. Reference demo Appointments

The initial demo dataset contains existing Appointments so that availability calculations, conflict detection and the public demo calendar operate with a realistic partially occupied schedule.

### Monday, 9 November 2026

**10:00–11:00**

- Service: Deep facial cleansing
- Professional: Laura Martín
- Status: Confirmed

**16:00–17:00**

- Service: Firming body treatment
- Professional: Sara Gómez
- Status: Confirmed

### Tuesday, 10 November 2026

**12:00–12:45**

- Service: Laser hair removal — full legs
- Professional: Marta Ruiz
- Status: Confirmed

**17:00–18:00**

- Service: Wellness massage
- Professional: Claudia Pérez
- Status: Confirmed

### Wednesday, 11 November 2026

**13:00–13:20**

- Service: Laser hair removal — underarms
- Professional: Sara Gómez
- Status: Confirmed

### Thursday, 12 November 2026

**09:30–11:00**

- Service: Premium facial treatment
- Professional: Laura Martín
- Status: Confirmed

### Friday, 13 November 2026

**10:00–11:00**

- Service: Hydrating facial treatment
- Professional: Sara Gómez
- Status: Confirmed

### Saturday, 14 November 2026

**10:30–11:30**

- Service: Firming body treatment
- Professional: Claudia Pérez
- Status: Confirmed

---

## 12. Public demo calendar

The public demo calendar may use the reference Appointments to demonstrate occupied and available scheduling periods.

It must not expose Customer personal information.

Public Appointment representations may expose information required for the demonstration, such as:

- Appointment time;
- duration;
- Service;
- Professional;
- occupancy state.

Customer identity or contact information must not be shown.

Creating, rescheduling or cancelling an Appointment through the conversational demo must be reflected in this calendar from the authoritative application state.

---

## 13. V1 boundaries

The reference Business deliberately does not require the following capabilities:

- rooms or cabins as schedulable Resources;
- machines as schedulable Resources;
- treatment packages or session bundles;
- deposits;
- online payments;
- waiting lists;
- automatic Professional assignment;
- multiple Business locations;
- medical records;
- clinical data;
- mandatory recurring Appointments;
- variable Service duration;
- multiple Professionals required for one Appointment.

These concepts may be considered in future product evolution but are not required to represent Lúmina Estética Avanzada in V1.

---

## 14. Use as application data

This document is the canonical reference for the Business configuration and demo data used by Appointment Assistant V1.

During V1, these values may be loaded into the application through seed or deployment data.

They must be persisted as normal application data rather than embedded in scheduling logic, conversational prompts or source-code conditionals.

A future Business administration interface should manage the same underlying configuration without requiring the core scheduling model to be redesigned.
