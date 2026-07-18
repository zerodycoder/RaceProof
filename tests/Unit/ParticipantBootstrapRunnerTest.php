<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Mockery;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\ParticipantBootstrap;
use RaceProof\Laravel\Data\BootstrapSpec;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Execution\ParticipantBootstrapRunner;
use stdClass;

final class ParticipantBootstrapRunnerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_does_nothing_without_a_bootstrap_spec(): void
    {
        $app = Mockery::mock(Application::class);
        $app->shouldNotReceive('make');

        (new ParticipantBootstrapRunner($app))->run(
            $this->plan(),
            new ParticipantContext(str_repeat('a', 32), 'p1'),
        );

        self::assertTrue(true);
    }

    public function test_it_resolves_and_runs_the_application_bootstrap(): void
    {
        $app = Mockery::mock(Application::class);
        $bootstrap = new RecordingParticipantBootstrap;
        $app->shouldReceive('make')->once()->with(RecordingParticipantBootstrap::class)->andReturn($bootstrap);
        $plan = $this->plan(new BootstrapSpec(RecordingParticipantBootstrap::class, ['tenant' => 'acme']));
        $context = new ParticipantContext($plan->runId, 'p2');

        (new ParticipantBootstrapRunner($app))->run($plan, $context);

        self::assertSame([[$context, ['tenant' => 'acme']]], $bootstrap->calls);
    }

    public function test_it_rejects_a_container_override_with_the_wrong_type(): void
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')->once()->andReturn(new stdClass);
        $plan = $this->plan(new BootstrapSpec(RecordingParticipantBootstrap::class));

        $this->expectException(InvalidRacePlan::class);
        $this->expectExceptionMessage('Resolved participant bootstrap');

        (new ParticipantBootstrapRunner($app))->run(
            $plan,
            new ParticipantContext($plan->runId, 'p1'),
        );
    }

    private function plan(?BootstrapSpec $bootstrap = null): RacePlan
    {
        return new RacePlan(
            str_repeat('a', 32),
            2,
            new RequestSpec('POST', '/checkout'),
            bootstrap: $bootstrap,
        );
    }
}

final class RecordingParticipantBootstrap implements ParticipantBootstrap
{
    /** @var list<array{ParticipantContext, array<string, mixed>}> */
    public array $calls = [];

    public function bootstrap(ParticipantContext $context, array $configuration): void
    {
        $this->calls[] = [$context, $configuration];
    }
}
