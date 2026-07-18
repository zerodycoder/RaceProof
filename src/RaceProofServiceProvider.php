<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use RaceProof\Laravel\Console\CleanCommand;
use RaceProof\Laravel\Console\DoctorCommand;
use RaceProof\Laravel\Console\WorkerCommand;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Execution\KernelRequestExecutor;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\Execution\SymfonyWorkerProcessFactory;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\SystemRaceClock;

final class RaceProofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/raceproof.php', 'raceproof');

        $this->app->singleton(FileCoordinatorStore::class, fn (): FileCoordinatorStore => new FileCoordinatorStore(
            ConfigValue::string($this->app->make(Config::class), 'raceproof.coordinator.path'),
        ));
        $this->app->alias(FileCoordinatorStore::class, CoordinatorStore::class);

        $this->app->singleton(RaceContext::class);
        $this->app->singleton(RacePoint::class);
        $this->app->singleton(RaceClock::class, SystemRaceClock::class);
        $this->app->singleton(WorkerProcessFactory::class, SymfonyWorkerProcessFactory::class);
        $this->app->bind(RequestExecutor::class, KernelRequestExecutor::class);
        $this->app->bind(RaceBuilder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/raceproof.php' => config_path('raceproof.php'),
        ], 'raceproof-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkerCommand::class,
                DoctorCommand::class,
                CleanCommand::class,
            ]);
        }
    }
}
