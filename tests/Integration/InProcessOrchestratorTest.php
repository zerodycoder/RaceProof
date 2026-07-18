<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\RaceBuilder;
use RaceProof\Laravel\Tests\Fixtures\Support\FixtureParticipantBootstrap;

final class InProcessOrchestratorTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_builder_and_orchestrator_run_real_workers_from_the_parent_process(): void
    {
        putenv('APP_ENV=testing');
        putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        putenv('RACEPROOF_ENABLED=true');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['RACEPROOF_ENABLED'] = $_SERVER['RACEPROOF_ENABLED'] = 'true';

        $fixture = dirname(__DIR__).'/Fixtures/app';
        $database = $fixture.'/storage/database.sqlite';

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0700, true);
        }

        if (! is_file($database)) {
            touch($database);
        }

        $app = require $fixture.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('raceproof.runner.cleanup_successful_runs', true);
        $app['config']->set('raceproof.runner.spawn_timeout_ms', 10_000);
        $app['config']->set('raceproof.runner.run_timeout_ms', 10_000);

        try {
            $result = $app->make(RaceBuilder::class)
                ->participants(3)
                ->postJson('/api/checkpoint', ['value' => 42])
                ->withHeaders(['X-RaceProof-Test' => 'builder'])
                ->withCookies(['raceproof' => 'cookie'])
                ->withToken('secret-test-token')
                ->startTogether()
                ->releaseWhenAllReach('inside-request')
                ->releaseWhenAllReach('inside-request')
                ->run();

            $result
                ->assertAllFinished()
                ->assertNoWorkerFailures()
                ->assertStatusCount(200, 3)
                ->assertNoTimeouts();

            self::assertNull($result->artifactPath);
        } finally {
            @unlink($database);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_class_bootstrap_configures_environment_config_and_auth_in_real_workers(): void
    {
        putenv('APP_ENV=testing');
        putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        putenv('RACEPROOF_ENABLED=true');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['RACEPROOF_ENABLED'] = $_SERVER['RACEPROOF_ENABLED'] = 'true';

        $fixture = dirname(__DIR__).'/Fixtures/app';
        $database = $fixture.'/storage/database.sqlite';

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0700, true);
        }

        if (! is_file($database)) {
            touch($database);
        }

        $app = require $fixture.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('raceproof.runner.cleanup_successful_runs', true);
        $app['config']->set('raceproof.runner.spawn_timeout_ms', 10_000);
        $app['config']->set('raceproof.runner.run_timeout_ms', 10_000);

        try {
            $result = $app->make(RaceBuilder::class)
                ->participants(3)
                ->postJson('/api/bootstrap')
                ->withBootstrap(FixtureParticipantBootstrap::class, [
                    'environment_prefix' => 'environment-',
                    'config_prefix' => 'configuration-',
                    'user_prefix' => 'user-',
                ])
                ->run();

            $result
                ->assertAllFinished()
                ->assertNoWorkerFailures()
                ->assertStatusCount(200, 3)
                ->assertNoTimeouts();

            foreach ($result->participants as $participant) {
                $body = json_decode($participant->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('environment-'.$participant->participantId, $body['environment']);
                self::assertSame('configuration-'.$participant->participantId, $body['configuration']);
                self::assertSame('user-'.$participant->participantId, $body['user_id']);
                self::assertTrue($body['checkpoint_active']);
            }

            self::assertCount(3, $result->timeline?->ofType('participant.bootstrap_started') ?? []);
            self::assertCount(3, $result->timeline?->ofType('participant.bootstrap_completed') ?? []);
            self::assertNull($result->artifactPath);
        } finally {
            @unlink($database);
            putenv('RACEPROOF_BOOTSTRAP_ENV');
        }
    }
}
