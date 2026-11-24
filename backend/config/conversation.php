<?php

$positiveSeconds = static function (mixed $value): ?int {
    if (! is_string($value) && ! is_int($value)) {
        return null;
    }

    $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $seconds === false ? null : $seconds;
};

$configuredDelays = env('CONVERSATION_RETRY_DELAYS_SECONDS');
$retryDelays = is_string($configuredDelays) ? array_map($positiveSeconds, explode(',', $configuredDelays)) : [];

return [
    'inactivity_seconds' => env('CONVERSATION_INACTIVITY_SECONDS', 12 * 60 * 60),
    'processing' => [
        'max_attempts' => (int) env('CONVERSATION_MAX_ATTEMPTS', 3),
        'retry_delays_seconds' => $retryDelays !== [] && ! in_array(null, $retryDelays, true) ? $retryDelays : [30, 120],
        'finalization_retry_seconds' => $positiveSeconds(env('CONVERSATION_FINALIZATION_RETRY_SECONDS')) ?? 120,
    ],
];
