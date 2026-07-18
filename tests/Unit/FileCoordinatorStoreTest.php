<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Support\RunId;

final class FileCoordinatorStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = dirname(__DIR__, 2).'/build/coordinator-'.bin2hex(random_bytes(4));
    }

    public function test_it_coordinates_a_complete_run_with_atomic_files(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $plan = new RacePlan(
            RunId::generate(),
            2,
            new RequestSpec('POST', '/checkout', ['product_id' => 1]),
            checkpoints: ['after-read'],
        );

        $store->createRun($plan);
        self::assertEquals($plan, $store->plan($plan->runId));

        $store->markReady($plan->runId, 'p1');
        $store->markReady($plan->runId, 'p2');
        self::assertSame(2, $store->readyCount($plan->runId));

        $store->releaseStart($plan->runId);
        $store->waitForStart($plan->runId, 10);

        $store->reachCheckpoint($plan->runId, 'p1', 'after-read');
        $store->reachCheckpoint($plan->runId, 'p2', 'after-read');
        self::assertSame(2, $store->checkpointCount($plan->runId, 'after-read'));
        $store->releaseCheckpoint($plan->runId, 'after-read');
        $store->waitForCheckpoint($plan->runId, 'after-read', 10);

        $result = new ParticipantResult($plan->runId, 'p1', 201, 100, 200, '{"ok":true}');
        $store->storeResult($result);
        self::assertEquals([$result], $store->results($plan->runId));

        $store->cleanup($plan->runId);
        self::assertDirectoryDoesNotExist($this->basePath.'/'.$plan->runId);
    }
}
