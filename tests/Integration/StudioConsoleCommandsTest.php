<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Console\Command;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Studio\ReportArchive;
use Symfony\Component\Process\Process;

final class StudioConsoleCommandsTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2).'/build/studio-console-tests/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.studio.enabled', true);
        $this->app['config']->set('raceproof.studio.path', $this->workspace.'/reports');
        $this->app['config']->set('raceproof.coordinator.path', $this->workspace.'/coordinator');
        $this->app['config']->set('raceproof.scaffolding.test_path', $this->workspace.'/tests/Feature');
        $this->app['config']->set('app.url', 'http://raceproof.test');
        $this->app->forgetInstance(ReportArchive::class);
        $this->app->forgetInstance(FileCoordinatorStore::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_make_race_test_generates_real_phpunit_and_pest_tests_without_fake_assertions(): void
    {
        $phpunitPath = $this->workspace.'/tests/Feature/InventoryRaceTest.php';
        $pestPath = $this->workspace.'/tests/Feature/WalletRaceTest.php';

        $this->artisan('make:race-test', [
            'name' => 'InventoryRace',
            'uri' => '/api/checkout',
            '--participants' => '4',
        ])
            ->expectsOutputToContain('Created')
            ->expectsOutputToContain('php artisan test --filter=InventoryRaceTest')
            ->assertExitCode(Command::SUCCESS);

        $this->artisan('make:race-test', [
            'name' => 'WalletRaceTest',
            'uri' => '/api/wallet/debit',
            '--pest' => true,
        ])->assertExitCode(Command::SUCCESS);

        $phpunit = file_get_contents($phpunitPath);
        $pest = file_get_contents($pestPath);

        self::assertIsString($phpunit);
        self::assertIsString($pest);
        self::assertStringContainsString('final class InventoryRaceTest', $phpunit);
        self::assertStringContainsString('->participants(4)', $phpunit);
        self::assertStringContainsString("->postJson('/api/checkout')", $phpunit);
        self::assertStringContainsString('->assertNoServerErrors()', $phpunit);
        self::assertStringNotContainsString('assertTrue(true)', $phpunit);
        self::assertStringContainsString("it('finishes concurrent requests", $pest);
        self::assertSame(0, $this->lint($phpunitPath));
        self::assertSame(0, $this->lint($pestPath));
    }

    public function test_make_race_test_rejects_unsafe_input_and_does_not_replace_without_force(): void
    {
        $arguments = [
            'name' => 'InventoryRace',
            'uri' => '/api/checkout',
        ];

        $this->artisan('make:race-test', $arguments)->assertExitCode(Command::SUCCESS);
        $this->artisan('make:race-test', $arguments)
            ->expectsOutputToContain('already exists')
            ->assertExitCode(Command::FAILURE);
        $this->artisan('make:race-test', [
            'name' => '../Escape',
            'uri' => '/api/checkout',
        ])->assertExitCode(Command::INVALID);
        $this->artisan('make:race-test', [
            'name' => 'UnsafeUri',
            'uri' => "https://example.test/\nInjected",
        ])->assertExitCode(Command::INVALID);
        $this->artisan('make:race-test', [
            'name' => 'BadParticipants',
            'uri' => '/api/checkout',
            '--participants' => '101',
        ])->assertExitCode(Command::INVALID);

        self::assertFileDoesNotExist($this->workspace.'/Escape.php');
    }

    public function test_reports_and_studio_commands_inspect_archived_evidence(): void
    {
        $runId = str_repeat('a', 32);
        $this->app->make(ReportArchive::class)->store($this->raceResult($runId));

        $this->artisan('raceproof:reports')
            ->expectsOutputToContain($runId)
            ->expectsOutputToContain('Outcome')
            ->assertExitCode(Command::SUCCESS);
        $this->artisan('raceproof:reports', ['run' => $runId])
            ->expectsOutputToContain('Participants')
            ->expectsOutputToContain('p1')
            ->assertExitCode(Command::SUCCESS);
        $this->artisan('raceproof:reports', ['run' => $runId, '--json' => true])
            ->expectsOutputToContain('"archive_schema": 1')
            ->assertExitCode(Command::SUCCESS);
        $this->artisan('raceproof:studio', ['run' => $runId])
            ->expectsOutputToContain('http://raceproof.test/raceproof/runs/'.$runId)
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_studio_commands_fail_closed_outside_local_and_testing(): void
    {
        $this->app['config']->set('app.env', 'production');

        $this->artisan('raceproof:reports')->assertExitCode(Command::FAILURE);
        $this->artisan('raceproof:studio')->assertExitCode(Command::FAILURE);
    }

    public function test_clean_removes_studio_reports_only_when_explicitly_requested(): void
    {
        $runId = str_repeat('f', 32);
        $archive = $this->app->make(ReportArchive::class);
        $archive->store($this->raceResult($runId));
        file_put_contents($this->workspace.'/reports/settings.json', '{"keep":true}');

        $this->artisan('raceproof:clean')->assertExitCode(Command::SUCCESS);
        self::assertNotNull($archive->find($runId));

        $this->artisan('raceproof:clean', ['--studio' => true])
            ->expectsOutputToContain('Removed 1 RaceProof Studio report(s).')
            ->assertExitCode(Command::SUCCESS);

        self::assertNull($archive->find($runId));
        self::assertFileExists($this->workspace.'/reports/settings.json');
    }

    private function raceResult(string $runId): RaceResult
    {
        return new RaceResult(
            runId: $runId,
            expectedParticipants: 2,
            participants: [
                new ParticipantResult($runId, 'p1', 200, 1_000_000, 2_000_000),
                new ParticipantResult($runId, 'p2', 200, 1_050_000, 2_100_000),
            ],
        );
    }

    private function lint(string $path): int
    {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->run();

        return $process->getExitCode() ?? 1;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
