<?php

declare(strict_types=1);

namespace RaceProof\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RaceProof\Laravel\Console\CleanCommand;
use RaceProof\Laravel\Console\DoctorCommand;
use RaceProof\Laravel\Console\InstallCommand;
use RaceProof\Laravel\Console\MakeRaceTestCommand;
use RaceProof\Laravel\Console\ReportsCommand;
use RaceProof\Laravel\Console\StudioCommand;
use RaceProof\Laravel\Console\WorkerAgentCommand;
use RaceProof\Laravel\Console\WorkerCommand;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\LocalWorkerProcessFactory;
use RaceProof\Laravel\Contracts\ParticipantClock;
use RaceProof\Laravel\Contracts\RaceClock;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Contracts\WorkerControlClock;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Contracts\WorkerProcessFactory;
use RaceProof\Laravel\Coordination\CoordinatorResolver;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Coordination\LaravelRedisClient;
use RaceProof\Laravel\Coordination\RedisCoordinatorStore;
use RaceProof\Laravel\Execution\KernelRequestExecutor;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\Execution\SymfonyWorkerProcessFactory;
use RaceProof\Laravel\Execution\WorkerTransportResolver;
use RaceProof\Laravel\Http\EnsureStudioRequestIsLocal;
use RaceProof\Laravel\Http\StudioController;
use RaceProof\Laravel\Remote\RedisSynchronizedParticipantClock;
use RaceProof\Laravel\Remote\RedisWorkerControlPlane;
use RaceProof\Laravel\Remote\RemoteControlMessageCodec;
use RaceProof\Laravel\Remote\RemoteWorkerConfiguration;
use RaceProof\Laravel\Remote\RemoteWorkerProcessFactory;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\SystemParticipantClock;
use RaceProof\Laravel\Support\SystemRaceClock;
use RaceProof\Laravel\Support\SystemWorkerControlClock;

final class RaceProofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/raceproof.php', 'raceproof');

        $this->app->singleton(FileCoordinatorStore::class, fn (): FileCoordinatorStore => new FileCoordinatorStore(
            ConfigValue::string($this->app->make(Config::class), 'raceproof.coordinator.path'),
        ));
        $this->app->singleton(RedisCoordinatorStore::class, function (): RedisCoordinatorStore {
            $config = $this->app->make(Config::class);
            $connectionName = ConfigValue::string(
                $config,
                'raceproof.coordinator.redis.connection',
            );

            return new RedisCoordinatorStore(
                new LaravelRedisClient(
                    $this->app->make(RedisFactory::class),
                    $connectionName,
                ),
                $connectionName,
                ConfigValue::string($config, 'raceproof.coordinator.redis.namespace'),
                ConfigValue::integer($config, 'raceproof.coordinator.redis.ttl_seconds', 86_400),
                ConfigValue::integer(
                    $config,
                    'raceproof.coordinator.redis.poll_interval_ms',
                    5,
                ),
            );
        });
        $this->app->singleton(CoordinatorResolver::class);
        $this->app->singleton(
            CoordinatorStore::class,
            fn (): CoordinatorStore => $this->app->make(CoordinatorResolver::class)->resolve(),
        );

        $this->app->singleton(RaceContext::class);
        $this->app->singleton(RacePoint::class);
        $this->app->singleton(RaceClock::class, SystemRaceClock::class);
        $this->app->singleton(ParticipantClock::class, function (): ParticipantClock {
            $config = $this->app->make(Config::class);

            if (ConfigValue::string($config, 'raceproof.worker_transport.driver') !== 'remote') {
                return new SystemParticipantClock;
            }

            $remote = $this->app->make(RemoteWorkerConfiguration::class);

            return new RedisSynchronizedParticipantClock(
                new LaravelRedisClient($this->app->make(RedisFactory::class), $remote->connection),
                $this->app->make(RaceClock::class),
                $remote->clockSyncMaxRttMs,
            );
        });
        $this->app->singleton(WorkerControlClock::class, SystemWorkerControlClock::class);
        $this->app->singleton(RemoteWorkerConfiguration::class, fn (): RemoteWorkerConfiguration => RemoteWorkerConfiguration::fromConfig(
            $this->app->make(Config::class),
        ));
        $this->app->singleton(RedisWorkerControlPlane::class, function (): RedisWorkerControlPlane {
            $config = $this->app->make(RemoteWorkerConfiguration::class);

            return new RedisWorkerControlPlane(
                new LaravelRedisClient(
                    $this->app->make(RedisFactory::class),
                    $config->connection,
                ),
                $config,
            );
        });
        $this->app->singleton(
            WorkerControlPlane::class,
            fn (): WorkerControlPlane => $this->app->make(RedisWorkerControlPlane::class),
        );
        $this->app->singleton(RemoteControlMessageCodec::class);
        $this->app->singleton(SymfonyWorkerProcessFactory::class);
        $this->app->singleton(
            LocalWorkerProcessFactory::class,
            fn (): LocalWorkerProcessFactory => $this->app->make(SymfonyWorkerProcessFactory::class),
        );
        $this->app->singleton(RemoteWorkerProcessFactory::class);
        $this->app->singleton(WorkerTransportResolver::class);
        $this->app->singleton(
            WorkerProcessFactory::class,
            function (): WorkerProcessFactory {
                $resolver = $this->app->make(WorkerTransportResolver::class);
                $resolver->driver();

                return $resolver;
            },
        );
        $this->app->singleton(ReportArchive::class);
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
                WorkerAgentCommand::class,
                InstallCommand::class,
                DoctorCommand::class,
                CleanCommand::class,
                MakeRaceTestCommand::class,
                ReportsCommand::class,
                StudioCommand::class,
            ]);
        }

        $archive = $this->app->make(ReportArchive::class);

        if (! $archive->available() || ! $this->app->bound('router')) {
            return;
        }

        $router = $this->app->make(Router::class);

        $router->group([
            'prefix' => $archive->routePrefix(),
            'as' => 'raceproof.studio.',
            'middleware' => EnsureStudioRequestIsLocal::class,
        ], static function (Router $router): void {
            $router->get('/', [StudioController::class, 'index'])->name('index');
            $router->get('/runs/{runId}', [StudioController::class, 'show'])
                ->where('runId', '[a-f0-9]{32}')
                ->name('show');
            $router->get('/runs/{runId}/report.json', [StudioController::class, 'download'])
                ->where('runId', '[a-f0-9]{32}')
                ->name('download');
        });
    }
}
