<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Exceptions\InvalidRacePlan;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\RacePoint;
use RaceProof\Laravel\Support\RunId;

final class UnregisteredRacePointTest extends TestCase
{
    public function test_an_active_worker_rejects_an_unregistered_checkpoint(): void
    {
        $path = dirname(__DIR__, 2).'/build/unregistered-'.bin2hex(random_bytes(4));
        $store = new FileCoordinatorStore($path);
        $plan = new RacePlan(RunId::generate(), 2, new RequestSpec('POST', '/checkout'));
        $store->createRun($plan);
        $context = new RaceContext;
        $context->activate($plan, 'p1', $store);

        try {
            $this->expectException(InvalidRacePlan::class);
            $this->expectExceptionMessage('not registered');

            (new RacePoint($context))->sync('missing');
        } finally {
            $store->cleanup($plan->runId);
        }
    }
}
