<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Evaluations\ConversationAgent\AppointmentOperationScenarios;
use Tests\Evaluations\ConversationAgent\AppointmentOperationsEvaluationRunner;
use Tests\Evaluations\ConversationAgent\ConversationAgentEvaluationRunner;

// [Developer] only. Validate arguments before autoloading, bootstrapping, or accessing a database.
$arguments = array_slice($argv, 1);
if (count($arguments) !== 3 || array_slice($arguments, 0, 2) !== ['--live', '--reset-evaluation-database']
    || preg_match('/^--scenario=([a-z-]+)$/D', $arguments[2] ?? '', $match) !== 1) {
    fwrite(STDOUT, "Developer-only Appointment operations evaluation (resets appointment_assistant_appointment_evaluation only).\nUsage: php backend/tests/Evaluations/ConversationAgent/run-appointment-operations.php --live --reset-evaluation-database --scenario=<name>\nScenario names and review procedures: tests/Evaluations/ConversationAgent/README.md\n");
    exit($arguments === ['--help'] ? 0 : 1);
}

umask(0077);
ini_set('display_errors', '0');
require __DIR__.'/../../../vendor/autoload.php';
if (! array_key_exists($match[1], AppointmentOperationScenarios::catalog())) {
    fwrite(STDERR, "Unknown scenario. No bootstrap, database reset or provider call performed.\n");
    exit(1);
}

try {
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['app.debug' => false, 'logging.default' => 'null']);
    if (! is_string(config('ai.providers.openai.key')) || config('ai.providers.openai.key') === ''
        || rtrim(config('ai.providers.openai.url'), '/') !== 'https://api.openai.com/v1') {
        throw new RuntimeException('Local official provider configuration is required.');
    }
    ConversationAgentEvaluationRunner::configureIsolatedDatabase(AppointmentOperationsEvaluationRunner::Database);
    $directory = storage_path('app/private/appointment-operations-evaluation');
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create private evidence directory.');
    }
    $lock = fopen($directory.'/run.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another Appointment evaluation is active.');
    }
    $output = $directory.'/'.gmdate('Ymd-His').'-'.Str::uuid().'.json';
    if (Artisan::call('migrate:fresh', ['--database' => 'conversation_agent_evaluation', '--force' => true]) !== 0) {
        throw new RuntimeException('Cannot migrate isolated evaluation database.');
    }
    $business = ConversationAgentEvaluationRunner::seed();
    $scenario = AppointmentOperationScenarios::make($business, $match[1], 'synthetic-evaluation-'.Str::uuid());
    fwrite(STDOUT, "Evidence: {$output}\nRunning one bounded scenario; maximum 64 HTTP attempts. No automatic provider retries.\n");
    $report = (new AppointmentOperationsEvaluationRunner($business, $output))->run($scenario);
    fwrite(STDOUT, "Appointment operations evaluation status: {$report['status']}\nEvidence: {$output}\nDeveloper review against persisted PostgreSQL records is still required.\n");
    exit($report['status'] === 'completed_needs_review' ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Appointment operations evaluation stopped ('.$exception::class."). Check setup and private evidence; no automatic retry.\n");
    exit(1);
}
