<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_environment_uses_postgresql(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(
            'appointment_assistant_test',
            DB::connection()->getDatabaseName(),
        );
    }
}
