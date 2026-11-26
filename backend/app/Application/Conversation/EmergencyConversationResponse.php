<?php

namespace App\Application\Conversation;

use DateTimeImmutable;

final class EmergencyConversationResponse
{
    private const NoOutcome = 'No pudimos completar tu solicitud. Puedes volver a intentarlo.';

    /** @param array<string, mixed>|null $result */
    public function build(?array $result): string
    {
        if (($result['status'] ?? null) === 'rejected') {
            return match ($result['outcome'] ?? null) {
                'declined' => 'Has rechazado la propuesta. No se realizó ningún cambio.',
                'slot_unavailable' => 'El horario solicitado ya no está disponible. No se realizó ningún cambio.',
                default => 'No se pudo completar la operación de la cita. No se realizó ningún cambio.',
            };
        }
        if (($result['status'] ?? null) !== 'completed') {
            return self::NoOutcome;
        }

        $response = match ($result['outcome'] ?? null) {
            'created' => 'Tu cita ha quedado reservada.',
            'rescheduled' => 'Tu cita ha sido reprogramada.',
            'cancelled' => 'Tu cita ha sido cancelada.',
            'unchanged' => 'Tu cita no ha cambiado.',
            default => null,
        };
        $snapshot = $result['appointment'] ?? null;

        return $response === null ? self::NoOutcome
            : $response.(is_array($snapshot) ? $this->details($snapshot) : '');
    }

    /** @param array<string, mixed> $snapshot */
    private function details(array $snapshot): string
    {
        $details = [];
        foreach (['service_name' => 'Servicio', 'professional_name' => 'Profesional'] as $field => $label) {
            $value = $snapshot[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $details[] = "$label: $value";
            }
        }

        // The committed snapshot already stores the local time and offset of the Business at execution.
        $startsAt = $snapshot['starts_at'] ?? null;
        $start = is_string($startsAt) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $startsAt) : false;
        if ($start !== false) {
            $details[] = 'Fecha y hora: '.$start->format('d/m/Y \a \l\a\s H:i:s');
        }

        return $details === [] ? '' : ' '.implode('. ', $details).'.';
    }
}
