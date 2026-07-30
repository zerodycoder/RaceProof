<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Contracts\LocalWorkerProcessFactory;
use RaceProof\Laravel\Contracts\WorkerControlClock;
use RaceProof\Laravel\Contracts\WorkerControlPlane;
use RaceProof\Laravel\Contracts\WorkerProcess;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\DatabaseSafety;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use Throwable;

/**
 * @internal
 */
final class RemoteWorkerAgent
{
    /**
     * @var array<string, array{
     *     process: WorkerProcess,
     *     run_id: string,
     *     participant_id: string,
     *     stopped: bool
     * }>
     */
    private array $processes = [];

    private ?int $lastHeartbeatMs = null;

    public function __construct(
        private readonly Config $laravelConfig,
        private readonly RemoteWorkerConfiguration $config,
        private readonly CoordinatorStore $coordinator,
        private readonly WorkerControlPlane $control,
        private readonly RemoteControlMessageCodec $codec,
        private readonly LocalWorkerProcessFactory $localFactory,
        private readonly WorkerControlClock $clock,
        private readonly EnvironmentGuard $environment,
        private readonly DatabaseSafety $database,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /** @param Closure(string): void|null $warning */
    public function run(string $agentId, int $idleTimeoutMs = 0, ?Closure $warning = null): void
    {
        $warning ??= static function (string $message): void {};
        $this->validate($agentId, $idleTimeoutMs);
        $lastActivity = $this->clock->monotonicMilliseconds();

        while (true) {
            if ($this->tick($agentId, $warning)) {
                $lastActivity = $this->clock->monotonicMilliseconds();
            }

            if (
                $idleTimeoutMs > 0
                && $this->processes === []
                && $this->clock->monotonicMilliseconds() - $lastActivity >= $idleTimeoutMs
            ) {
                return;
            }

            $this->clock->sleepMilliseconds($this->config->pollIntervalMs);
        }
    }

    /** @param Closure(string): void $warning */
    public function tick(string $agentId, Closure $warning): bool
    {
        $this->config->assertAgent($agentId);
        $now = $this->clock->monotonicMilliseconds();

        if (
            $this->lastHeartbeatMs === null
            || $now - $this->lastHeartbeatMs >= max(1, intdiv($this->config->heartbeatTtlMs, 3))
        ) {
            $this->control->heartbeat($agentId);
            $this->lastHeartbeatMs = $now;
        }

        $activity = $this->consumeStops($agentId, $warning);
        $activity = $this->settle() || $activity;

        while (count($this->processes) < $this->config->maxConcurrency) {
            $encoded = $this->control->next($agentId, RemoteControlMessage::ACTION_START);

            if ($encoded === null) {
                break;
            }

            $activity = true;

            try {
                $message = $this->codec->decode($encoded, $agentId);

                if ($message->action !== RemoteControlMessage::ACTION_START || ! $this->control->claim($message)) {
                    continue;
                }

                $this->launch($message);
            } catch (Throwable) {
                $warning('Rejected an invalid remote worker start control.');
            }
        }

        return $this->settle() || $activity;
    }

    private function validate(string $agentId, int $idleTimeoutMs): void
    {
        $this->environment->ensureEnabled();
        $this->database->validate();
        $this->config->assertAgent($agentId);

        if (
            ConfigValue::string($this->laravelConfig, 'raceproof.worker_transport.driver') !== 'remote'
            || $this->coordinator->driver() !== 'redis'
        ) {
            throw new RaceProofException(
                'RaceProof remote worker agent requires the remote transport and Redis coordinator.',
            );
        }

        if ($idleTimeoutMs < 0 || $idleTimeoutMs > 600_000) {
            throw new RaceProofException('RaceProof remote worker agent idle timeout is invalid.');
        }

        $this->localFactory->healthCheck();
        $this->control->healthCheck();
    }

    /** @param Closure(string): void $warning */
    private function consumeStops(string $agentId, Closure $warning): bool
    {
        $activity = false;
        $limit = $this->config->maxConcurrency * 4;

        for ($count = 0; $count < $limit; $count++) {
            $encoded = $this->control->next($agentId, RemoteControlMessage::ACTION_STOP);

            if ($encoded === null) {
                break;
            }

            $activity = true;

            try {
                $message = $this->codec->decode($encoded, $agentId);

                if ($message->action !== RemoteControlMessage::ACTION_STOP || ! $this->control->claim($message)) {
                    continue;
                }

                $key = $this->key($message->runId, $message->participantId);
                $active = $this->processes[$key] ?? null;

                if ($active !== null) {
                    $active['process']->stop($this->config->shutdownTimeoutMs / 1_000);
                    $active['stopped'] = true;
                    $this->processes[$key] = $active;
                } else {
                    $state = $this->control->state($message->runId, $message->participantId);

                    if ($state !== null && ! $state->terminal()) {
                        $warning('Remote worker stop control has no local process handle.');
                    }
                }
            } catch (Throwable) {
                $warning('Rejected an invalid remote worker stop control.');
            }
        }

        return $activity;
    }

    private function launch(RemoteControlMessage $message): void
    {
        $process = null;

        try {
            $process = $this->localFactory->create($message->runId, $message->participantId);
            $process->start();

            if (! $this->control->markRunning($message)) {
                $process->stop($this->config->shutdownTimeoutMs / 1_000);
                $exitCode = $process->wait();
                $this->control->finish(
                    $message->runId,
                    $message->participantId,
                    $exitCode,
                    $this->bounded($process->output()),
                    $this->bounded($process->errorOutput()),
                    true,
                );

                return;
            }

            $this->processes[$this->key($message->runId, $message->participantId)] = [
                'process' => $process,
                'run_id' => $message->runId,
                'participant_id' => $message->participantId,
                'stopped' => false,
            ];
        } catch (Throwable $exception) {
            $settlementError = '';

            if ($process !== null) {
                try {
                    if ($process->isRunning()) {
                        $process->stop($this->config->shutdownTimeoutMs / 1_000);
                    }
                } catch (Throwable $stopException) {
                    $settlementError = ' Stop failed: '.$stopException->getMessage();
                }

                try {
                    $process->wait();
                } catch (Throwable $waitException) {
                    $settlementError .= ' Wait failed: '.$waitException->getMessage();
                }
            }

            $this->control->finish(
                $message->runId,
                $message->participantId,
                1,
                '',
                $this->redactor->bounded(
                    'Remote worker launch failed: '.$exception->getMessage().$settlementError,
                    $this->config->outputBytes,
                ),
                false,
            );
        }
    }

    private function settle(): bool
    {
        $activity = false;

        foreach ($this->processes as $key => $active) {
            $process = $active['process'];

            if ($process->isRunning()) {
                continue;
            }

            $activity = true;
            $error = '';

            try {
                $exitCode = $process->wait();
            } catch (Throwable $exception) {
                $exitCode = $process->exitCode() ?? 1;
                $error = $exception->getMessage();
            }

            $errorOutput = trim($process->errorOutput()."\n".$error);
            $this->control->finish(
                $active['run_id'],
                $active['participant_id'],
                $exitCode,
                $this->bounded($process->output()),
                $this->bounded($errorOutput),
                $active['stopped'],
            );
            unset($this->processes[$key]);
        }

        return $activity;
    }

    private function bounded(string $value): string
    {
        return $this->redactor->bounded($value, $this->config->outputBytes);
    }

    private function key(string $runId, string $participantId): string
    {
        return "{$runId}:{$participantId}";
    }
}
