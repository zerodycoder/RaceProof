<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\ConsumerParticipantBootstrap;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RaceProof\Laravel\ParticipantBuilder;
use RaceProof\Laravel\RaceProofServiceProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConsumerAcceptanceTest extends TestCase
{
    public function test_real_consumer_install_auth_cli_race_and_studio_workflow(): void
    {
        self::assertTrue($this->app->providerIsLoaded(RaceProofServiceProvider::class));

        self::assertSame(0, Artisan::call('raceproof:doctor', [
            '--json' => true,
            '--self-test' => true,
        ]));
        $doctor = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $doctor['schema_version']);
        self::assertTrue($doctor['ok']);
        self::assertContains('laravel-child-process', array_column($doctor['checks'], 'id'));

        $users = $this->createUsers();

        $this->assertAuthenticationModes($users);
        $this->assertParticipantOverridesAndBootstrap($users);

        $firstCouponRun = $this->runCouponInvariant();

        $this->artisan('raceproof:reports')
            ->expectsOutputToContain($firstCouponRun)
            ->assertExitCode(0);
        $this->artisan('raceproof:reports', ['run' => $firstCouponRun, '--json' => true])
            ->expectsOutputToContain("\"run_id\": \"{$firstCouponRun}\"")
            ->assertExitCode(0);
        $this->artisan('raceproof:studio', ['run' => $firstCouponRun])
            ->expectsOutputToContain("/raceproof/runs/{$firstCouponRun}")
            ->assertExitCode(0);

        $this->assertScaffoldingWorkflow();

        $this->artisan('raceproof:clean', ['--studio' => true])
            ->expectsOutputToContain('RaceProof Studio report(s).')
            ->assertExitCode(0);
        self::assertSame([], glob(storage_path('framework/raceproof-studio/*.json')) ?: []);

        $browserRun = $this->runCouponInvariant();
        File::put(storage_path('framework/consumer-acceptance-run'), $browserRun."\n");

        $this->get('/raceproof')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertSee($browserRun);
        $this->get("/raceproof/runs/{$browserRun}")
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Execution lanes')
            ->assertSee('coupon-claim')
            ->assertSee('Participant outcomes');
        $this->get("/raceproof/runs/{$browserRun}/report.json")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('report.run.run_id', $browserRun);
    }

    /** @param array<int, User> $users */
    private function assertAuthenticationModes(array $users): void
    {
        $sessionCookie = $this->sessionCookie($users[1]);
        $sanctumToken = $users[3]->createToken('consumer-acceptance', ['probe'])->plainTextToken;

        $result = race()
            ->participants(3)
            ->postJson('/auth-probe')
            ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
                ->withCookies(['raceproof_consumer_session' => $sessionCookie]))
            ->forParticipant('p2', fn (ParticipantBuilder $participant) => $participant
                ->withToken('legacy-token-two'))
            ->forParticipant('p3', fn (ParticipantBuilder $participant) => $participant
                ->withToken($sanctumToken))
            ->run();

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoTimeouts()
            ->assertStatusCount(200, 3);

        foreach ($result->participants as $participant) {
            $body = json_decode($participant->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame((int) substr($participant->participantId, 1), $body['user_id']);
        }
    }

    /** @param array<int, User> $users */
    private function assertParticipantOverridesAndBootstrap(array $users): void
    {
        $result = race()
            ->participants(2)
            ->postJson('/api/participant-context', ['payload' => 'global'])
            ->withHeaders(['X-Participant' => 'global'])
            ->withCookies(['participant' => 'global'])
            ->withToken('global-token')
            ->actingAs($users[2])
            ->withBootstrap(ConsumerParticipantBootstrap::class, ['tenant' => 'global'])
            ->forParticipant('p1', fn (ParticipantBuilder $participant) => $participant
                ->withPayload(['payload' => 'first'])
                ->withHeaders(['X-Participant' => 'first'])
                ->withCookies(['participant' => 'first'])
                ->withToken('first-token')
                ->actingAs($users[1])
                ->withBootstrap(ConsumerParticipantBootstrap::class, ['tenant' => 'north']))
            ->run();

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoTimeouts()
            ->assertStatusCount(200, 2);

        $expected = [
            'p1' => ['first', 'first', 'first', hash('sha256', 'first-token'), 1, 'north:p1'],
            'p2' => ['global', 'global', 'global', hash('sha256', 'global-token'), 2, 'global:p2'],
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
    }

    private function runCouponInvariant(): string
    {
        DB::table('coupons')->delete();
        DB::table('coupons')->insert([
            'id' => 1,
            'code' => 'ONLY-ONCE',
            'redeemed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = race()
            ->participants(3)
            ->postJson('/api/coupons/1/redeem', ['user_id' => 42])
            ->releaseWhenAllReach('coupon-claim')
            ->run();

        $result
            ->assertAllFinished()
            ->assertNoWorkerFailures()
            ->assertNoTimeouts()
            ->assertNoServerErrors()
            ->assertStatusCount(201, 1)
            ->assertStatusCount(409, 2)
            ->assertInvariant(
                fn (): bool => DB::table('coupons')->where('id', 1)->value('redeemed_by') === 42,
                'Exactly one participant must redeem the coupon.',
            );

        return $result->runId;
    }

    private function assertScaffoldingWorkflow(): void
    {
        $directory = storage_path('framework/consumer-generated');
        config()->set('raceproof.scaffolding.test_path', $directory);

        $this->artisan('make:race-test', [
            'name' => 'GeneratedCouponRace',
            'uri' => '/api/coupons/1/redeem',
            '--participants' => '3',
        ])->assertExitCode(0);

        $path = $directory.'/GeneratedCouponRaceTest.php';
        self::assertFileExists($path);
        $contents = File::get($path);
        self::assertStringContainsString('->participants(3)', $contents);
        self::assertStringContainsString("->postJson('/api/coupons/1/redeem')", $contents);
        self::assertStringNotContainsString('assertTrue(true)', $contents);
        self::assertStringNotContainsString('use function RaceProof\\Laravel\\race', $contents);

        self::assertFalse(class_exists('Pest\\TestSuite'));

        $this->artisan('make:race-test', [
            'name' => 'UnavailablePestRace',
            'uri' => '/api/participant-context',
            '--pest' => true,
        ])
            ->expectsOutputToContain('Pest is not installed.')
            ->expectsOutputToContain('composer require pestphp/pest --dev')
            ->assertExitCode(Command::FAILURE);

        self::assertFileDoesNotExist($directory.'/UnavailablePestRaceTest.php');

        $executablePath = base_path('tests/Feature/GeneratedAssertionCountRaceTest.php');
        config()->set('raceproof.scaffolding.test_path', dirname($executablePath));
        File::delete($executablePath);

        try {
            $this->artisan('make:race-test', [
                'name' => 'GeneratedAssertionCountRace',
                'uri' => '/api/participant-context',
                '--participants' => '2',
            ])->assertExitCode(Command::SUCCESS);

            DB::purge();

            $process = new Process(
                [PHP_BINARY, 'artisan', 'test', '--filter=GeneratedAssertionCountRaceTest'],
                base_path(),
                timeout: 60,
            );
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            self::assertStringNotContainsString('risky', strtolower($process->getOutput()));
            self::assertStringContainsString('4 assertions', $process->getOutput());
        } finally {
            File::delete($executablePath);
        }
    }

    /** @return array<int, User> */
    private function createUsers(): array
    {
        $users = [];

        foreach ([1, 2, 3] as $id) {
            $users[$id] = User::query()->create([
                'id' => $id,
                'name' => "Consumer {$id}",
                'email' => "consumer{$id}@example.test",
                'password' => 'not-used',
                'api_token' => $id === 2 ? 'legacy-token-two' : null,
            ]);
        }

        return $users;
    }

    private function sessionCookie(User $user): string
    {
        $sessions = $this->app->make(SessionManager::class);
        $session = $sessions->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put($this->app['auth']->guard('web')->getName(), $user->getAuthIdentifier());
        $session->save();

        $cookieName = $this->app['config']->get('session.cookie');
        $encrypter = $this->app->make(Encrypter::class);

        self::assertIsString($cookieName);

        return $encrypter->encrypt(
            CookieValuePrefix::create($cookieName, $encrypter->getKey()).$session->getId(),
            EncryptCookies::serialized($cookieName),
        );
    }
}
