<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Mockery;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Execution\SymfonyWorkerProcess;
use RaceProof\Laravel\Execution\SymfonyWorkerProcessFactory;

final class SymfonyWorkerProcessFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_builds_a_managed_process_for_the_fixture_application(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/app';
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('basePath')->with('artisan')->andReturn($fixture.'/artisan');
        $app->shouldReceive('basePath')->withNoArgs()->andReturn($fixture);
        $store = Mockery::mock(CoordinatorStore::class);
        $store->shouldReceive('basePath')->andReturn('/tmp/raceproof');

        $process = (new SymfonyWorkerProcessFactory($app, $store))->create(str_repeat('a', 32), 'p1');

        self::assertInstanceOf(SymfonyWorkerProcess::class, $process);
    }

    public function test_it_rejects_an_application_without_artisan(): void
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('basePath')->with('artisan')->andReturn('/missing/artisan');
        $store = Mockery::mock(CoordinatorStore::class);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('artisan file was not found');

        (new SymfonyWorkerProcessFactory($app, $store))->create(str_repeat('a', 32), 'p1');
    }
}
