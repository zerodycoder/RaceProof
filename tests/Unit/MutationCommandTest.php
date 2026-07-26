<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class MutationCommandTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_mutation_gate_uses_a_cross_platform_file_scope(): void
    {
        $root = dirname(__DIR__, 2);
        $contents = file_get_contents($root.'/composer.json');

        self::assertNotFalse($contents);

        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('scripts', $composer);
        self::assertIsArray($composer['scripts']);
        self::assertArrayHasKey('test:mutation', $composer['scripts']);
        self::assertIsString($composer['scripts']['test:mutation']);

        $command = $composer['scripts']['test:mutation'];
        $paths = [
            'src/Support/EnvironmentGuard.php',
            'src/Support/DatabaseSafety.php',
            'src/Support/SensitiveDataRedactor.php',
            'src/Execution/SymfonyWorkerProcess.php',
        ];

        self::assertStringContainsString('--path='.implode(',', $paths), $command);
        self::assertStringNotContainsString('--class=', $command);

        foreach ($paths as $path) {
            self::assertFileExists($root.'/'.$path);
        }
    }
}
