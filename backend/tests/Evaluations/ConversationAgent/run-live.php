<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Evaluations\ConversationAgent\ConversationAgentEvaluationRunner;

// [Developer] only. No Laravel bootstrap, database access or API call without both flags.
if (array_slice($argv, 1) !== ['--live', '--reset-smoke-database']) {
    fwrite(STDOUT, "Developer-only ConversationAgent evaluation (resets appointment_assistant_s11_smoke only).\nUsage: php backend/tests/Evaluations/ConversationAgent/run-live.php --live --reset-smoke-database\n");
    exit(array_slice($argv, 1) === ['--help'] ? 0 : 1);
}

umask(0077);
ini_set('display_errors', '0');
require __DIR__.'/../../../vendor/autoload.php';

try {
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['app.debug' => false, 'logging.default' => 'null']);
    if (! is_string(config('ai.providers.openai.key')) || config('ai.providers.openai.key') === '') {
        throw new RuntimeException('Missing local provider configuration.');
    }
    if (rtrim(config('ai.providers.openai.url'), '/') !== 'https://api.openai.com/v1') {
        throw new RuntimeException('Only the official OpenAI endpoint is permitted.');
    }
    ConversationAgentEvaluationRunner::configureIsolatedDatabase();
    $directory = storage_path('app/private/s11-smoke');
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create private evidence directory.');
    }
    $lock = fopen($directory.'/run.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another smoke run is active.');
    }
    $output = $directory.'/'.gmdate('Ymd-His').'-'.Str::uuid().'.json';
    if (Artisan::call('migrate:fresh', ['--database' => 'conversation_agent_evaluation', '--force' => true]) !== 0) {
        throw new RuntimeException('Cannot migrate isolated smoke database.');
    }
    $runner = new ConversationAgentEvaluationRunner(ConversationAgentEvaluationRunner::seed(), $output);
    fwrite(STDOUT, "Evidence: {$output}\nRunning descriptive/availability probes, then the remaining scenarios. Maximum 48 HTTP requests; no automatic retries.\n");
    $report = $runner->run();
    fwrite(STDOUT, "ConversationAgent evaluation status: {$report['status']}\nEvidence: {$output}\nDeveloper response-quality review is still required.\n");
    exit($report['status'] === 'completed_needs_review' ? 0 : 2);
} catch (Throwable $exception) {
    // Never print provider/SQL exception messages, headers, configuration or stack traces.
    fwrite(STDERR, 'ConversationAgent evaluation stopped ('.$exception::class."). Check local setup and any saved private evidence; no automatic retry.\n");
    exit(1);
}
