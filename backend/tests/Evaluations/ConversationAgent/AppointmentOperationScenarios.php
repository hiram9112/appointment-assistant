<?php

namespace Tests\Evaluations\ConversationAgent;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ChannelIdentity;
use App\Models\Customer;
use App\Models\ProfessionalAvailabilityInterval;
use Carbon\CarbonImmutable;

/** Synthetic conversations and human rubrics, not a semantic judge or customer simulator. */
final class AppointmentOperationScenarios
{
    /** @return array<string, string> */
    public static function catalog(): array
    {
        return [
            'registration-continuity' => 'Collect names, respect phone refusal, register once and retain booking details in the same Conversation.',
            'registered-lifecycle' => 'List, create, move retaining Laura, explicitly switch to Sara, and cancel. Each write requires its own later confirmation.',
            'negative-reply' => 'Decline the proposal without booking. A later yes must not revive it.',
            'ambiguous-reply' => 'An ambiguous reply is not approval; clarify without executing. Only the later explicit yes authorizes execution.',
            'changed-selections' => 'Conditional approval and changes of time, Professional and date replace, never execute, the earlier proposal.',
            'premature-reply' => 'Both a same-turn yes and a persisted yes received before the accepted question are insufficient. Request a later confirmation.',
            'occupied-slot' => 'The competing booking is committed after presentation. Report slot_unavailable, not success; a fresh alternative requires confirmation.',
            'ambiguous-appointments' => 'List only this Customer/Business records; ask which Appointment before preparing cancellation.',
            'mixed-intent' => 'Answer the EUR 55 price question and ask for booking confirmation coherently, without losing either part.',
            'prepared-provider-recovery' => 'After injected interruption, present the same saved proposal, not a new availability check or proposal.',
            'prepared-assistant-recovery' => 'Failed assistant persistence leaves prepared, not pending. Retry presents unchanged persisted details.',
            'completed-provider-recovery' => 'After committed creation and injected interruption, report the durable result without executing again. A later repeated yes must preserve that known success, clarify intent, and never authorize a second booking.',
            'completed-assistant-recovery' => 'After committed creation but failed assistant persistence, retry reports success with exactly one audit. A later repeated yes must not contradict the recovered success or revive the completed proposal; verify current state with scoped Appointment reads only if needed.',
            'new-conversation' => 'After 12 hours, no old history or proposal is inherited; persistent identity and Appointments remain accessible.',
            'tomorrow' => 'At Madrid Monday 00:30 (UTC Sunday), mañana is Tuesday 10 November. Compare local dates and exact persisted instants.',
            'weekday' => 'El jueves means 12 November; retain explicitly chosen Sara and the exact start.',
            'month-boundary' => 'At 30 November, mañana means 1 December, not 31 November.',
            'spring-dst' => 'Madrid 29 March 02:30 does not exist: clarify. The chosen 03:30 uses +02:00, not the prior day offset.',
            'autumn-dst' => 'The explicitly chosen second 02:30 on 25 October uses +01:00. Do not silently select the first occurrence.',
            'unchanged-reschedule' => 'The Customer explicitly requests no changes: verify the scoped Appointment and acknowledge it without preparing or executing an operation or producing an audit. The technical confirmed unchanged outcome is covered separately by deterministic backend tests.',
            'information-regression' => 'Answer address/payment/consultation questions from configuration; informational availability must invoke S03 without booking.',
        ];
    }

    /** @return array<string, mixed> */
    public static function make(Business $business, string $name, string $sender): array
    {
        $review = self::catalog()[$name] ?? throw new \InvalidArgumentException('Unknown evaluation scenario.');
        $service = $business->services()->where('name', 'Deep facial cleansing')->sole();
        $laura = $business->professionals()->where('first_name', 'Laura')->sole();
        $sara = $business->professionals()->where('first_name', 'Sara')->sole();
        $customer = null;
        if ($name !== 'registration-continuity') {
            $customer = Customer::factory()->create(['first_name' => 'Ana', 'last_name' => 'Prueba', 'phone' => null]);
            ChannelIdentity::factory()->for($customer)->create(['channel' => 'whatsapp', 'external_id' => $sender]);
        }
        $create = fn (string $start = '2026-11-09T15:30:00+01:00', ?int $professional = null): array => [
            'service_id' => $service->id, 'professional_id' => $professional ?? $laura->id, 'starts_at' => $start,
        ];
        $call = fn (string $tool, array $arguments = []): array => ['name' => $tool, 'arguments' => $arguments];
        $plain = fn (string $prompt): array => ['prompt' => $prompt, 'calls' => [], 'effect' => 'none'];
        $prepare = fn (string $prompt, string $operation, array $arguments): array => [
            'prompt' => $prompt, 'calls' => [$call('Prepare'.ucfirst($operation).'AppointmentTool', $arguments)],
            'effect' => 'prepared', 'operation' => $operation, 'arguments' => $arguments,
        ];
        $confirm = fn (string $outcome = 'created'): array => [
            'prompt' => 'Sí, confirmo exactamente esa propuesta.',
            'calls' => [$call('ConfirmAppointmentProposalTool', ['decision' => 'confirm'])],
            'effect' => $outcome === 'slot_unavailable' ? 'rejected' : 'completed', 'outcome' => $outcome,
        ];
        $initial = $prepare('Quiero reservar Deep facial cleansing con Laura Martín hoy a las 15:30.', 'create', $create());
        $list = ['prompt' => '¿Qué citas tengo?', 'calls' => [$call('GetCustomerAppointmentsTool')], 'effect' => 'none', 'listing' => true];
        $time = '2026-11-09T00:30:00+01:00';
        $overlays = [];
        $targets = [];
        if (in_array($name, ['ambiguous-appointments', 'unchanged-reschedule', 'new-conversation'], true)) {
            foreach ($name === 'ambiguous-appointments' ? ['2026-11-11T12:00:00+01:00', '2026-11-12T12:00:00+01:00'] : ['2026-11-11T12:00:00+01:00'] as $start) {
                $targets[] = self::appointment($business, $customer, $start)->id;
            }
        }
        $turns = match ($name) {
            'registration-continuity' => [
                $plain($initial['prompt']),
                [...$initial, 'prompt' => 'Soy Ana Prueba. Prefiero no dar mi teléfono. Sigue con la reserva que te pedí.',
                    'calls' => [$call('RegisterCustomerTool', ['first_name' => 'Ana', 'last_name' => 'Prueba', 'phone' => null]), ...$initial['calls']], 'registration' => true],
                $confirm(), $list,
            ],
            'registered-lifecycle' => [
                $list, $initial, $confirm(), $list,
                $prepare('Mueve esa cita a las 15:45 de ese mismo lunes, manteniendo la profesional.', 'reschedule', ['appointment_id' => '@created', 'starts_at' => '2026-11-09T15:45:00+01:00']),
                $confirm('rescheduled'),
                $prepare('Ahora pásala al jueves 12 de noviembre a las 12:30, esta vez con Sara Gómez.', 'reschedule', ['appointment_id' => '@created', 'professional_id' => $sara->id, 'starts_at' => '2026-11-12T12:30:00+01:00']),
                $confirm('rescheduled'),
                $prepare('Quiero cancelar esa cita.', 'cancel', ['appointment_id' => '@created']),
                $confirm('cancelled'),
            ],
            'negative-reply' => [
                $initial, ['prompt' => 'No, no la reserves.', 'calls' => [$call('ConfirmAppointmentProposalTool', ['decision' => 'decline'])], 'effect' => 'rejected', 'outcome' => 'declined'],
                $plain('Sí.'),
            ],
            'ambiguous-reply' => [$initial, $plain('Puede ser, no lo tengo claro. ¿Qué opinas?'), $confirm()],
            'changed-selections' => [
                $initial,
                $prepare('Sí, pero mejor a las doce del mismo lunes con Laura.', 'create', $create('2026-11-09T12:00:00+01:00')),
                $prepare('Mejor con Sara Gómez a las 12:30 de ese lunes.', 'create', $create('2026-11-09T12:30:00+01:00', $sara->id)),
                $prepare('Cambia la fecha al jueves 12 de noviembre, misma hora y Sara.', 'create', $create('2026-11-12T12:30:00+01:00', $sara->id)),
                $confirm(),
            ],
            'premature-reply' => [
                [...$initial, 'prompt' => $initial['prompt'].' Sí, confirmo ya.', 'queue_premature' => true],
                [...$plain('Sí, confirmo antes de recibir la pregunta.'), 'queued' => true], $confirm(),
            ],
            'occupied-slot' => [
                $initial, [...$confirm('slot_unavailable'), 'occupy' => true],
                $prepare('Entonces quiero a Laura hoy a las doce.', 'create', $create('2026-11-09T12:00:00+01:00')), $confirm(),
            ],
            'ambiguous-appointments' => [
                [...$list, 'prompt' => 'Quiero cancelar mi cita. ¿Cuál tengo?'],
                $prepare('La del miércoles 11 de noviembre a las doce con Laura.', 'cancel', ['appointment_id' => $targets[0]]), $confirm('cancelled'),
            ],
            'mixed-intent' => [[...$initial, 'prompt' => '¿Cuánto cuesta Deep facial cleansing? Además quiero reservarlo con Laura Martín hoy a las 15:30.'], $confirm()],
            'prepared-provider-recovery', 'prepared-assistant-recovery' => [
                [...$initial, 'interrupt' => str_contains($name, 'provider') ? 'after_preparation' : 'assistant'],
                [...$initial, 'resume' => true, 'calls' => []], $confirm(),
            ],
            'completed-provider-recovery', 'completed-assistant-recovery' => [
                $initial, [...$confirm(), 'interrupt' => str_contains($name, 'provider') ? 'after_execution' : 'assistant'],
                [...$confirm(), 'resume' => true, 'calls' => []], $plain('Sí, otra vez.'),
            ],
            'new-conversation' => [$initial, [...$list, 'prompt' => 'Sí. ¿Qué citas tengo?', 'advance_hours' => 12, 'new_conversation' => true]],
            'tomorrow' => [$prepare('Reserva Deep facial cleansing con Laura Martín mañana a las 12:15.', 'create', $create('2026-11-10T12:15:00+01:00')), $confirm()],
            'weekday' => [$prepare('Reserva Deep facial cleansing con Sara Gómez el jueves a las 12:30.', 'create', $create('2026-11-12T12:30:00+01:00', $sara->id)), $confirm()],
            'month-boundary' => [$prepare('Reserva Deep facial cleansing con Laura Martín mañana a las 12:15.', 'create', $create('2026-12-01T12:15:00+01:00')), $confirm()],
            'spring-dst' => [
                $plain('Reserva Deep facial cleansing con Laura Martín mañana 29 de marzo a las 02:30.'),
                $prepare('Entonces el 29 de marzo a las 03:30 con Laura, después del cambio de hora.', 'create', $create('2026-03-29T03:30:00+02:00')), $confirm(),
            ],
            'autumn-dst' => [
                $prepare('Reserva Deep facial cleansing con Laura Martín mañana domingo 25 de octubre a las 02:30, la segunda vez que el reloj marca esa hora, después de atrasarlo.', 'create', $create('2026-10-25T02:30:00+01:00')), $confirm(),
            ],
            'unchanged-reschedule' => [
                [...$list, 'prompt' => 'Mantén mi cita del miércoles 11 de noviembre a las doce, con Laura, sin cambiar ningún dato.'],
            ],
            'information-regression' => [
                $plain('¿Dónde estáis, aceptáis efectivo y cuánto cuesta y dura Initial beauty consultation?'),
                ['prompt' => 'Solo consulta disponibilidad de Deep facial cleansing con Laura hoy a las 15:30, no reserves nada.',
                    'calls' => [$call('CheckAvailabilityTool', ['service_id' => $service->id, 'professional_id' => $laura->id, 'from' => '2026-11-09T15:30:00+01:00', 'to' => '2026-11-09T15:30:00+01:00'])], 'effect' => 'none'],
            ],
        };
        if ($name === 'month-boundary') {
            $time = '2026-11-30T00:30:00+01:00';
        }
        if (in_array($name, ['spring-dst', 'autumn-dst'], true)) {
            $time = $name === 'spring-dst' ? '2026-03-28T12:00:00+01:00' : '2026-10-24T12:00:00+02:00';
            BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'sunday', 'opens_at' => '01:00', 'closes_at' => '06:00']);
            ProfessionalAvailabilityInterval::factory()->for($laura)->create(['weekday' => 'sunday', 'starts_at' => '01:00', 'ends_at' => '06:00']);
            $overlays[] = 'Business and Laura open Sunday 01:00–06:00 for DST evaluation only.';
        }

        return ['name' => $name, 'sender' => $sender, 'review_rubric' => $review, 'operation_time' => $time, 'turns' => $turns,
            'fixture' => ['customer_id' => $customer?->id, 'target_appointment_ids' => $targets, 'overlays' => $overlays]];
    }

    public static function appointment(Business $business, Customer $customer, string $start): Appointment
    {
        $service = $business->services()->where('name', 'Deep facial cleansing')->sole();
        $professional = $business->professionals()->where('first_name', 'Laura')->sole();
        $instant = CarbonImmutable::parse($start);

        return Appointment::factory()->for($customer)->for($service)->for($professional)->create([
            'starts_at' => $instant, 'ends_at' => $instant->addMinutes($service->duration_minutes),
            'buffer_before_minutes' => $service->buffer_before_minutes, 'buffer_after_minutes' => $service->buffer_after_minutes,
        ]);
    }
}
