<?php

namespace Tests\Feature;

use App\Application\Conversation\ConversationHandler;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('races')]
    public function test_concurrent_deliveries_are_serialized(bool $existing, bool $duplicate): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.conversation_race' => config("database.connections.$default")]);
        DB::setDefaultConnection('conversation_race');
        $business = Business::factory()->create();
        if ($existing) {
            (new ConversationHandler)->inbound($business, 'web', 'sender', 'initial', 'initial');
        }
        DB::setDefaultConnection($default);

        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
            fwrite(STDOUT, "pid:$pid\n");
            if ($argv[3] === 'first') {
                App\Models\ConversationMessage::creating(function (): void {
                    fwrite(STDOUT, "locked\n");
                    if (trim(fgets(STDIN)) !== 'commit') { throw new RuntimeException('Barrier failed'); }
                });
            }
            try {
                $business = App\Models\Business::findOrFail($argv[1]);
                $message = (new App\Application\Conversation\ConversationHandler)->inbound($business, 'web', 'sender', $argv[2], 'same');
                fwrite(STDOUT, "message:$message->id\n");
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage());
                exit(1);
            }
            PHP;
        $workers = [];
        try {
            foreach (['first', 'second'] as $index => $name) {
                $id = $index === 0 || $duplicate ? 'first' : 'second';
                $process = proc_open([PHP_BINARY, '-r', $code, (string) $business->id, $id, $name], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']));
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 15);
                $line = fgets($pipes[1]);
                $this->assertMatchesRegularExpression('/^pid:\d+\n$/', $line ?: '');
                $pids[] = (int) substr($line, 4);
                if ($index === 0) {
                    $this->assertSame("locked\n", fgets($pipes[1]));
                }
            }
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::connection('conversation_race')->selectOne('select case when ? = any(pg_blocking_pids(?)) then 1 else 0 end as blocked', [$pids[0], $pids[1]])->blocked === 1;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Second delivery did not wait for the participant lock.');
            fwrite($workers[0][1][0], "commit\n");
            $ids = [];
            foreach ($workers as $index => [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertMatchesRegularExpression('/^message:\d+\n$/', $line ?: '');
                $ids[] = (int) substr($line, 8);
                fclose($pipes[0]);
                fclose($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                unset($workers[$index]);
                $this->assertSame(0, $exit, $error);
            }
            $this->assertSame($duplicate, $ids[0] === $ids[1]);
            $db = DB::connection('conversation_race');
            $conversations = $db->table('conversations')->where('business_id', $business->id)->pluck('id');
            $this->assertCount(1, $conversations);
            $this->assertSame(($existing ? 1 : 0) + ($duplicate ? 1 : 2), $db->table('conversation_messages')->whereIn('conversation_id', $conversations)->count());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            $db = DB::connection('conversation_race');
            $ids = $db->table('conversations')->where('business_id', $business->id)->pluck('id');
            $db->table('conversation_messages')->whereIn('conversation_id', $ids)->delete();
            $db->table('conversations')->whereIn('id', $ids)->delete();
            $db->table('businesses')->where('id', $business->id)->delete();
            DB::purge('conversation_race');
        }
    }

    public static function races(): array
    {
        return ['first distinct' => [false, false], 'existing distinct' => [true, false], 'duplicates' => [false, true]];
    }
}
