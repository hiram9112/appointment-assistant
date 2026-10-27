<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    public function test_php_version_is_supported(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
    }
}
