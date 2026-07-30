<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use RaceProof\Laravel\Remote\RemoteWorkerAgent;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

final class WorkerAgentCommand extends Command
{
    protected $signature = 'raceproof:worker-agent
        {--id= : Registered remote agent identifier}
        {--idle-timeout-ms=0 : Exit after this bounded idle period; zero keeps polling}';

    protected $description = 'Run a bounded RaceProof remote worker agent';

    public function __construct(
        private readonly Container $container,
        private readonly SensitiveDataRedactor $redactor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $agentId = $this->option('id');
        $idleTimeout = $this->option('idle-timeout-ms');

        if (
            ! is_string($agentId)
            || $agentId === ''
            || ! is_string($idleTimeout)
            || preg_match('/^[0-9]+$/D', $idleTimeout) !== 1
        ) {
            $this->components->error('A valid registered agent ID and idle timeout are required.');

            return self::INVALID;
        }

        try {
            $agent = $this->container->make(RemoteWorkerAgent::class);

            if (! $agent instanceof RemoteWorkerAgent) {
                throw new \RuntimeException('RaceProof resolved an invalid remote worker agent.');
            }

            $agent->run(
                $agentId,
                (int) $idleTimeout,
                function (string $message): void {
                    $this->components->warn($message);
                },
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($this->redactor->diagnostic($exception->getMessage()));

            return self::FAILURE;
        }
    }
}
