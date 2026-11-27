<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $pdo->sqliteCreateFunction('acos', static fn (?float $x): ?float => $x === null ? null : acos(max(-1.0, min(1.0, (float) $x))), 1);
            $pdo->sqliteCreateFunction('cos', static fn (?float $x): ?float => $x === null ? null : cos((float) $x), 1);
            $pdo->sqliteCreateFunction('sin', static fn (?float $x): ?float => $x === null ? null : sin((float) $x), 1);
            $pdo->sqliteCreateFunction('radians', static fn (?float $x): ?float => $x === null ? null : deg2rad((float) $x), 1);
        }
    }
}
