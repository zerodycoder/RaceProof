<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Execution\ParticipantBootstrapRunner;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\RacePoint;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
use RaceProof\Runtime\Checkpoint;
use RaceProof\Runtime\CheckpointActivation;
use Throwable;

final class WorkerCommand extends Command
{
    protected $signature = 'raceproof:worker
        {--run= : Race run ID}
        {--participant= : Participant ID}
        {--coordinator= : Absolute coordinator directory}';

    protected $description = 'Internal RaceProof worker process';

    public function __construct(
        private readonly RequestExecutor $executor,
        private readonly RaceContext $context,
        private readonly EnvironmentGuard $environment,
        private readonly SensitiveDataRedactor $redactor,
        private readonly ParticipantBootstrapRunner $bootstrapRunner,
        private readonly RacePoint $checkpointHandler,
    ) {
        parent::__construct();
        $this->setHidden(true);
    }

    public function handle(): int
    {
        $runOption = $this->option('run');
        $participantOption = $this->option('participant');
        $coordinatorOption = $this->option('coordinator');

        if (! is_string($runOption) || ! is_string($participantOption) || ! is_string($coordinatorOption)) {
            $this->components->error('Worker options must be strings.');

            return self::INVALID;
        }

        $runId = $runOption;
        $participantId = $participantOption;
        $coordinator = $coordinatorOption;
        $store = new FileCoordinatorStore($coordinator);
        $plan = null;
        $activation = null;

        try {
            $this->environment->ensureEnabled();
            $plan = $store->plan($runId);
            $participantContext = new ParticipantContext($runId, $participantId);
            $bootstrapSpec = $plan->bootstrapFor($participantId);

            if ($bootstrapSpec !== null) {
                $store->recordEvent(TimelineEvent::make(
                    $runId,
                    'participant.bootstrap_started',
                    $participantId,
                    data: ['class' => $bootstrapSpec->class],
                ));

                try {
                    $this->bootstrapRunner->run($plan, $participantContext);
                } catch (Throwable $bootstrapException) {
                    $store->recordEvent(TimelineEvent::make(
                        $runId,
                        'participant.bootstrap_failed',
                        $participantId,
                        data: [
                            'exception_class' => $bootstrapException::class,
                            'message' => $this->redactor->diagnostic($bootstrapException->getMessage()),
                        ],
                    ));
                    throw $bootstrapException;
                }

                $store->recordEvent(TimelineEvent::make($runId, 'participant.bootstrap_completed', $participantId));
            }

            $this->context->activate($plan, $participantId, $store);
            $store->markReady($runId, $participantId);
            $store->waitForStart($runId, $plan->spawnTimeoutMs);
            $activation = Checkpoint::activate($this->checkpointHandler);
            $result = $this->executor->execute($plan, $participantContext);
            $store->storeResult($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $this->redactor->diagnostic($exception->getMessage());

            if ($plan !== null) {
                try {
                    $store->storeResult(ParticipantResult::workerFailure(
                        $runId,
                        $participantId,
                        $exception::class.': '.$message,
                    ));
                } catch (Throwable) {
                    // The parent process also captures STDERR and the exit code.
                }
            }

            $this->components->error($message);

            return self::FAILURE;
        } finally {
            if ($activation instanceof CheckpointActivation && Checkpoint::active()) {
                Checkpoint::deactivate($activation);
            }

            $this->context->clear();
        }
    }
}
