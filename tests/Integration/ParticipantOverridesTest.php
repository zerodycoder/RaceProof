<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\ParticipantBuilder;
use RaceProof\Laravel\RaceBuilder;
use RaceProof\Laravel\Tests\Fixtures\Models\FixtureUser;
use RaceProof\Laravel\Tests\Fixtures\Support\FixtureParticipantBootstrap;

final class ParticipantOverridesTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_distinct_request_identity_and_bootstrap_specs_reach_real_workers(): void
    {
        $app = $this->bootFixture();
        $users = $this->createUsers();

        try {
            $result = $app->make(RaceBuilder::class)
                ->participants(3)
                ->postJson('/api/participant-spec', ['payload' => 'global'])
                ->withHeaders(['X-Participant' => 'global'])
                ->withCookies(['participant' => 'global'])
                ->withToken('token-global')
                ->actingAs($users[3])
                ->withBootstrap(FixtureParticipantBootstrap::class, [
                    'environment_prefix' => 'global-',
                    'config_prefix' => 'global-',
                    'user_prefix' => 'bootstrap-global-',
                ])
                ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
                    ->withPayload(['payload' => 'one'])
                    ->withHeaders(['X-Participant' => 'one'])
                    ->withCookies(['participant' => 'one'])
                    ->withToken('token-one')
                    ->actingAs($users[1])
                    ->withBootstrap(FixtureParticipantBootstrap::class, [
                        'environment_prefix' => 'one-',
                        'config_prefix' => 'one-',
                        'user_prefix' => 'bootstrap-one-',
                    ]))
                ->forParticipant('p2', fn (ParticipantBuilder $participant) => $participant
                    ->withPayload(['payload' => 'two'])
                    ->withHeaders(['X-Participant' => 'two'])
                    ->withCookies(['participant' => 'two'])
                    ->withToken('token-two')
                    ->actingAs($users[2])
                    ->withBootstrap(FixtureParticipantBootstrap::class, [
                        'environment_prefix' => 'two-',
                        'config_prefix' => 'two-',
                        'user_prefix' => 'bootstrap-two-',
                    ]))
                ->run();

            $result
                ->assertAllFinished()
                ->assertNoWorkerFailures()
                ->assertStatusCount(200, 3)
                ->assertNoTimeouts();

            $expected = [
                'p1' => ['one', 'one', 'one', hash('sha256', 'token-one'), 1, 'one-p1'],
                'p2' => ['two', 'two', 'two', hash('sha256', 'token-two'), 2, 'two-p2'],
                'p3' => ['global', 'global', 'global', hash('sha256', 'token-global'), 3, 'global-p3'],
            ];

            foreach ($result->participants as $participant) {
                $body = json_decode($participant->body, true, 512, JSON_THROW_ON_ERROR);

                self::assertSame($expected[$participant->participantId], [
                    $body['payload'],
                    $body['header'],
                    $body['cookie'],
                    $body['token_hash'],
                    $body['user_id'],
                    $body['bootstrap'],
                ]);
            }

            self::assertCount(3, $result->timeline?->ofType('participant.bootstrap_started') ?? []);
            self::assertCount(3, $result->timeline?->ofType('participant.bootstrap_completed') ?? []);
            self::assertNull($result->artifactPath);
        } finally {
            $this->cleanFixture();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_session_legacy_token_and_sanctum_token_authenticate_real_workers(): void
    {
        $app = $this->bootFixture();
        $users = $this->createUsers(apiTokenForUserTwo: 'legacy-token-two');
        $sessionCookie = $this->sessionCookie($app, $users[1]);
        $sanctumToken = $users[3]->createToken('raceproof-participant-three', ['probe'])->plainTextToken;

        try {
            $result = $app->make(RaceBuilder::class)
                ->participants(3)
                ->postJson('/auth-probe')
                ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
                    ->withCookies(['raceproof_session' => $sessionCookie]))
                ->forParticipant('p2', fn (ParticipantBuilder $participant) => $participant
                    ->withToken('legacy-token-two'))
                ->forParticipant('p3', fn (ParticipantBuilder $participant) => $participant
                    ->withToken($sanctumToken))
                ->run();

            $result
                ->assertAllFinished()
                ->assertNoWorkerFailures()
                ->assertStatusCount(200, 3)
                ->assertNoTimeouts();

            foreach ($result->participants as $participant) {
                $body = json_decode($participant->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame((int) substr($participant->participantId, 1), $body['user_id']);
            }

            self::assertNull($result->artifactPath);
        } finally {
            $this->cleanFixture();
        }
    }

    private function bootFixture(): Application
    {
        putenv('APP_ENV=testing');
        putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        putenv('RACEPROOF_ENABLED=true');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $_ENV['RACEPROOF_ENABLED'] = $_SERVER['RACEPROOF_ENABLED'] = 'true';

        $this->cleanFixture();
        $fixture = $this->fixturePath();
        touch($fixture.'/storage/database.sqlite');

        $app = require $fixture.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('raceproof.runner.cleanup_successful_runs', true);
        $app['config']->set('raceproof.runner.spawn_timeout_ms', 10_000);
        $app['config']->set('raceproof.runner.run_timeout_ms', 10_000);

        $schema = $app['db']->connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('api_token')->nullable();
        });
        $schema->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        return $app;
    }

    /** @return array<int, FixtureUser> */
    private function createUsers(?string $apiTokenForUserTwo = null): array
    {
        $users = [];

        foreach ([1, 2, 3] as $id) {
            $users[$id] = FixtureUser::query()->create([
                'id' => $id,
                'name' => 'Participant '.$id,
                'api_token' => $id === 2 ? $apiTokenForUserTwo : null,
            ]);
        }

        return $users;
    }

    private function sessionCookie(Application $app, FixtureUser $user): string
    {
        $sessions = $app->make(SessionManager::class);
        $session = $sessions->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put($app['auth']->guard('web')->getName(), $user->getAuthIdentifier());
        $session->save();

        $cookieName = $app['config']->get('session.cookie');
        $encrypter = $app->make(Encrypter::class);

        self::assertIsString($cookieName);

        return $encrypter->encrypt(
            CookieValuePrefix::create($cookieName, $encrypter->getKey()).$session->getId(),
            EncryptCookies::serialized($cookieName),
        );
    }

    private function cleanFixture(): void
    {
        $fixture = $this->fixturePath();
        @unlink($fixture.'/storage/database.sqlite');

        foreach (glob($fixture.'/storage/framework/sessions/*') ?: [] as $session) {
            if (is_file($session) && basename($session) !== '.gitkeep') {
                @unlink($session);
            }
        }
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__).'/Fixtures/app';
    }
}
