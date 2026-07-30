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
        self::assertIsArray($composer['scripts']['test:mutation']);
        self::assertSame('Composer\\Config::disableProcessTimeout', $composer['scripts']['test:mutation'][0]);

        $command = $composer['scripts']['test:mutation'][1] ?? null;
        self::assertIsString($command);
        $paths = [
            'src/Support/EnvironmentGuard.php',
            'src/Support/DatabaseSafety.php',
            'src/Support/SensitiveDataRedactor.php',
            'src/Execution/SymfonyWorkerProcess.php',
            'src/Execution/RaceOrchestrator.php',
            'src/Execution/WorkerTransportResolver.php',
            'src/Coordination/CoordinatorResolver.php',
            'src/Coordination/FileCoordinatorStore.php',
            'src/Coordination/RedisCoordinatorStore.php',
            'src/Remote/RemoteWorkerConfiguration.php',
            'src/Remote/RemoteControlMessageCodec.php',
            'src/Remote/RedisWorkerControlPlane.php',
            'src/Remote/RemoteWorkerProcess.php',
            'src/Reports/RaceReportFactory.php',
        ];
        $tests = [
            'tests/Integration/SafetyGuardEdgesTest.php',
            'tests/Integration/SafetyGuardsTest.php',
            'tests/Integration/SensitiveDataRedactorTest.php',
            'tests/Unit/SymfonyWorkerProcessStopTest.php',
            'tests/Integration/RaceOrchestratorLifecycleTest.php',
            'tests/Unit/WorkerTransportResolverTest.php',
            'tests/Unit/CoordinatorResolverTest.php',
            'tests/Unit/FileCoordinatorStoreTest.php',
            'tests/Unit/FileCoordinatorTimelineTest.php',
            'tests/Unit/RedisCoordinatorStoreTest.php',
            'tests/Unit/RemoteWorkerConfigurationTest.php',
            'tests/Unit/RemoteControlMessageCodecTest.php',
            'tests/Unit/RedisWorkerControlPlaneTest.php',
            'tests/Unit/RemoteWorkerProcessTest.php',
            'tests/Unit/CoordinatorStoreContractTest.php',
            'tests/Unit/RaceResultReportTest.php',
            'tests/Unit/ReportModelTest.php',
            'tests/Integration/ReportersTest.php',
        ];

        self::assertStringContainsString('--path='.implode(',', $paths), $command);
        self::assertStringNotContainsString('--class=', $command);

        foreach ($paths as $path) {
            self::assertFileExists($root.'/'.$path);
        }

        foreach ($tests as $test) {
            self::assertStringContainsString(' '.$test, $command);
            self::assertFileExists($root.'/'.$test);
        }
    }
}
