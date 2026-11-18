<?php

namespace App\Application\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class BusinessLocalTimestamp
{
    public function parse(string $value, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $value = preg_replace('/Z$/', '+00:00', $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Use YYYY-MM-DDTHH:MM:SS with an explicit UTC offset in the Business timezone.']);
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($instant === false || $errors !== false || $instant->format(DATE_ATOM) !== $value || $instant->setTimezone($zone)->format(DATE_ATOM) !== $value) {
            throw ValidationException::withMessages([$field => 'Use a valid Business-local timestamp with seconds and an explicit UTC offset matching the Business timezone.']);
        }

        return $instant->setTimezone($zone);
    }
}
