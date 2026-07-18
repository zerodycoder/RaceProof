<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class OversellingDemoTest extends TestCase
{
    public function test_race_point_reproduces_overselling_and_the_atomic_fix_holds(): void
    {
        $script = __DIR__.'/../Fixtures/overselling-app/run-demo.php';
        $process = new Process([PHP_BINARY, $script], dirname(__DIR__, 2), timeout: 45);
        $process->mustRun();

        /** @var array<string, mixed> $demo */
        $demo = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $demo['broken']['result']['statuses']['201']);
        self::assertSame(-2, $demo['broken']['stock']);
        self::assertSame(3, $demo['broken']['orders']);

        self::assertSame(1, $demo['fixed']['result']['statuses']['201']);
        self::assertSame(2, $demo['fixed']['result']['statuses']['409']);
        self::assertSame(0, $demo['fixed']['stock']);
        self::assertSame(1, $demo['fixed']['orders']);
    }
}
