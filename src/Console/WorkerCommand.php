<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Contracts\RequestExecutor;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantContext;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\Support\EnvironmentGuard;
use RaceProof\Laravel\Support\SensitiveDataRedactor;
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

        try {
            $this->environment->ensureEnabled();
            $plan = $store->plan($runId);
            $this->context->activate($plan, $participantId, $store);
            $store->markReady($runId, $participantId);
            $store->waitForStart($runId, $plan->spawnTimeoutMs);
            $result = $this->executor->execute($plan, new ParticipantContext($runId, $participantId));
            $store->storeResult($result);
            $this->context->clear();

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
            $this->context->clear();
        }
    }
}
