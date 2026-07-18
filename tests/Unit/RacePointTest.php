<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Execution\RaceContext;
use RaceProof\Laravel\RacePoint;
use RaceProof\Laravel\Support\RunId;

final class RacePointTest extends TestCase
{
    public function test_it_is_a_no_op_without_an_active_worker_context(): void
    {
        $point = new RacePoint(new RaceContext);
        $point->sync('not-registered', 1);

        self::assertTrue(true);
    }

    public function test_it_reaches_and_waits_on_a_registered_checkpoint(): void
    {
        $path = dirname(__DIR__, 2).'/build/racepoint-'.bin2hex(random_bytes(4));
        $store = new FileCoordinatorStore($path);
        $plan = new RacePlan(
            RunId::generate(),
            2,
            new RequestSpec('POST', '/checkout'),
            checkpoints: ['stock-read'],
        );
        $store->createRun($plan);
        $store->releaseCheckpoint($plan->runId, 'stock-read');
        $context = new RaceContext;
        $context->activate($plan, 'p1', $store);

        (new RacePoint($context))->sync('stock-read', 10);

        self::assertSame(1, $store->checkpointCount($plan->runId, 'stock-read'));
        $store->cleanup($plan->runId);
    }
}
