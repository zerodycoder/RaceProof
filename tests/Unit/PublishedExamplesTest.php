<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishedExamplesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function examples(): iterable
    {
        yield 'overselling' => ['overselling', '/oversell/fixed', 'oversell-claim'];
        yield 'coupon redemption' => ['coupon-redemption', '/coupon/fixed', 'coupon-claim'];
        yield 'wallet debit' => ['wallet-debit', '/wallet/fixed', 'wallet-claim'];
        yield 'quote acceptance' => ['quote-acceptance', '/quote/fixed', 'quote-claim'];
    }

    #[DataProvider('examples')]
    public function test_published_example_is_executable_and_wired_to_engine_evidence(
        string $directory,
        string $fixedRoute,
        string $checkpoint,
    ): void {
        $root = dirname(__DIR__, 2);
        $routesPath = "{$root}/examples/{$directory}/routes.php";
        $readmePath = "{$root}/examples/{$directory}/README.md";
        $fixture = file_get_contents("{$root}/tests/Fixtures/database-app/routes/api.php");
        $routes = file_get_contents($routesPath);
        $readme = file_get_contents($readmePath);

        self::assertIsString($fixture);
        self::assertIsString($routes);
        self::assertIsString($readme);
        self::assertStringContainsString("/examples/{$directory}/routes.php", $fixture);
        self::assertStringContainsString($fixedRoute, $routes);
        self::assertStringContainsString($checkpoint, $routes);
        self::assertStringContainsString('[routes.php](routes.php)', $readme);
        self::assertStringContainsString('broken:', $readme);
        self::assertStringContainsString('fixed:', $readme);
    }
}
