### Task 7 verification record — 2026-11-20

**Verification status:** Completed. Final Developer acceptance and roadmap update remain separate actions.

#### Automated verification

The corrective implementation pass completed the following automated checks:

| Verification                                                     | Result                          |
| ---------------------------------------------------------------- | ------------------------------- |
| Complete backend suite (`composer test`)                         | 700 tests passed                |
| Focused Conversation Agent and evaluation tests                  | 166 passed                      |
| Rescheduling and occupancy integration tests                     | 39 passed                       |
| Laravel Pint                                                     | Passed                          |
| Composer lint                                                    | Passed                          |
| Changed PHP syntax checks                                        | Passed                          |
| PostgreSQL integration, transaction, and concurrency regressions | Passed within the backend suite |

The focused test counts are separate verification runs and must not be added to the full-suite total.

The S11 evaluation harness initially exceeded its cumulative request-byte budget after the agent instruction changes. The instructions were shortened, and the affected tests subsequently passed without increasing request limits or relaxing assertions. No separate static-analysis tool was configured.

These automated results were reported by the coding agent. Live-model evaluation and Developer review were performed separately.

#### Live-model evaluation

**Evaluation date:** 2026-11-20  
**Provider:** OpenAI  
**Model:** `gpt-6-luna`  
**Environment:** Local evaluation environment with isolated, seeded PostgreSQL fixtures  
**Business timezone:** `Europe/Madrid`  
**Evaluation suite:** `appointment-operations`  
**Scenarios reviewed:** 21

The evaluation runner exercised bounded, real-model conversations using synthetic Business, Customer, Appointment, and Conversation data. Scenario-specific operation times were fixed to make relative-date and daylight-saving-time behavior reproducible.

Evidence included model responses, executed tool names and arguments, structured tool results, contract checks, Conversation history, runtime context, and before/after PostgreSQL snapshots.

Each scenario was manually reviewed against both its expected conversational behavior and authoritative persisted state. Automated contract success alone was not treated as sufficient evidence of correctness.

The following scenarios were examined:

| Coverage area                          | Scenarios                                                                                   |
| -------------------------------------- | ------------------------------------------------------------------------------------------- |
| Core conversational workflows          | `mixed-intent`, `registered-lifecycle`, `registration-continuity`, `information-regression` |
| Confirmation and proposal changes      | `negative-reply`, `ambiguous-reply`, `changed-selections`, `premature-reply`                |
| Availability and Appointment selection | `occupied-slot`, `ambiguous-appointments`                                                   |
| Prepared-proposal recovery             | `prepared-provider-recovery`, `prepared-assistant-recovery`                                 |
| Completed-operation recovery           | `completed-provider-recovery`, `completed-assistant-recovery`                               |
| Conversation isolation                 | `new-conversation`                                                                          |
| Natural dates and timezone behavior    | `tomorrow`, `weekday`, `month-boundary`, `spring-dst`, `autumn-dst`                         |
| No-change requests                     | `unchanged-reschedule`                                                                      |

The initial manual review produced:

| Classification                                  | Count |
| ----------------------------------------------- | ----: |
| Accepted scenarios                              |    19 |
| Functional defect requiring correction          |     1 |
| Evaluation-design mismatch requiring correction |     1 |
| Total scenarios reviewed                        |    21 |

The `completed-provider-recovery` scenario also revealed a non-blocking confirmation-wording issue.

#### Findings and corrective actions

**1. Completed-operation conversational continuity**

Scenario: `completed-assistant-recovery`

The original execution successfully created an Appointment and recovered its durable result after an injected assistant-response persistence failure. However, a subsequent separate Customer message, `Sí, otra vez.`, caused the agent to incorrectly state that it could not verify the previously confirmed booking.

The underlying recovery, persistence, and idempotency mechanisms were functioning correctly. The defect concerned how the agent interpreted a previously communicated successful result on a later conversational turn.

The agent instructions were updated to distinguish authoritative current-turn execution results from successful outcomes already established in completed Conversation history. A repeated affirmative must not be interpreted as authorization for another operation.

The corrected live evaluation confirmed that the agent acknowledges the previous booking and requests clarification before considering another reservation.

**2. Confirmation wording**

Scenario: `completed-provider-recovery`

The initial evaluation produced a confirmation question equivalent to `¿Confirmas que prepare esta reserva?`, although the proposal had already been prepared.

The agent and confirmation-tool instructions were clarified to distinguish proposal preparation, Customer authorization to execute, and completed execution.

The corrected evaluation generated:

> He preparado la reserva de **Deep facial cleansing** con **Laura Martín** para hoy, **9 de noviembre, de 15:30 a 16:30**. ¿Confirmas esta reserva?

The model remains responsible for generating customer-facing language. No deterministic confirmation renderer, response rewriting, or secondary semantic-validation mechanism was introduced.

**3. Unchanged rescheduling evaluation**

Scenario: `unchanged-reschedule`

The original Customer request explicitly instructed the agent to keep an existing Appointment unchanged. The agent correctly verified the Appointment and made no modifications, but the evaluation contract incorrectly required preparation and confirmation of a rescheduling operation.

The live evaluation contract was corrected to expect a Business- and Customer-scoped Appointment read with no write operations.

The technical `unchanged` outcome remains covered separately by deterministic PostgreSQL tests, including same-slot preparation, confirmation boundaries, self-occupancy exclusion, ownership checks, unchanged persistence, and absence of unnecessary audits.

This correction preserved both conversational correctness and the underlying domain-level coverage.

#### Corrective regression verification

After implementing the corrections, three affected scenarios were rerun against the live OpenAI model.

| Scenario                       | Run ID                                 | Manual result |
| ------------------------------ | -------------------------------------- | ------------- |
| `completed-assistant-recovery` | `0d626725-4a7a-45e7-8f3d-67a7bcdc0a11` | PASS          |
| `completed-provider-recovery`  | `0a4147ff-286a-49bc-938c-0db49e3de403` | PASS          |
| `unchanged-reschedule`         | `5e423c9d-55a9-4a70-8e52-193a0f2814fa` | PASS          |

**Completed assistant-response recovery**

The evaluation verified that:

- An accepted confirmation executes the canonical persisted proposal.
- The Appointment and audit are committed exactly once.
- An injected interruption leaves the committed operation recoverable.
- Retrying the confirming turn reports the persisted successful result without repeating Appointment tools.
- A later ambiguous affirmative does not reactivate the proposal, create another Appointment, or contradict the known confirmation.

The scenario completed all four turn contracts successfully. PostgreSQL contained one new confirmed Appointment (`#9`), one corresponding `created` audit, and a terminal `completed` proposal.

**Completed provider/execution recovery**

The evaluation verified the improved confirmation wording, successful recovery following an injected `after_execution` interruption, and correct handling of a subsequent repeated affirmative.

The scenario completed all four turn contracts successfully. Recovery performed no duplicate execution, and the final persisted state contained one new Appointment, one audit, and no reactivated proposal.

**Unchanged rescheduling**

The corrected evaluation executed `GetCustomerAppointmentsTool` and verified that the existing confirmed Appointment remained unchanged.

The agent made no preparation or confirmation calls, created no proposal, modified no Appointment, and generated no audit. The scenario passed its updated contract.

#### Acceptance assessment

The combined initial review and targeted corrective regressions support the following outcome:

- **21 of 21 scenarios have an accepted manual review outcome** after correction.
- The identified conversational defect was corrected and successfully reevaluated.
- The confirmation-wording observation was addressed and successfully reevaluated.
- The unchanged-rescheduling evaluation contract was aligned with Customer intent, while deterministic backend coverage retained the technical `unchanged` guarantee.
- No duplicate Appointment creation, unexpected audit, or proposal reactivation was observed in the corrective recovery evaluations.
- Previously accepted scenarios were not all rerun after the corrections; the post-correction live verification targeted the three affected scenarios, supplemented by the complete automated backend regression suite.

The evidence supports acceptance of the implemented S12 behavior within the evaluated scope. It does not establish statistical reliability across every possible model response, nor does it verify external WhatsApp message delivery, which remains outside S12.

The full generated JSON evidence is retained in the local private evaluation storage under:

`backend/storage/app/private/appointment-operations-evaluation/`

These raw execution records are not required in the Git repository. The committed evaluation runner, scenarios, automated tests, documentation, and this verification record provide the reproducible methodology and summarized acceptance evidence.

**Final Developer actions:** Review the complete implementation diff, record formal acceptance, and update `v1.md` only after approval. Git operations remain under Developer control.
