<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Coordination\RedisCoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Tests\Support\InMemoryRedisClient;

final class CoordinatorStoreContractTest extends TestCase
{
    /** @return iterable<string, array{Closure(): CoordinatorStore, Closure(): void}> */
    public static function stores(): iterable
    {
        $path = dirname(__DIR__, 2).'/build/coordinator-contract-file';

        yield 'file' => [
            static fn (): CoordinatorStore => new FileCoordinatorStore($path),
            static function () use ($path): void {
                self::removeDirectory($path);
            },
        ];

        yield 'redis' => [
            static fn (): CoordinatorStore => new RedisCoordinatorStore(
                new InMemoryRedisClient,
                'default',
                'raceproof-contract',
                60,
                1,
            ),
            static function (): void {},
        ];
    }

    #[DataProvider('stores')]
    public function test_backends_share_the_same_observable_lifecycle(
        Closure $factory,
        Closure $cleanup,
    ): void {
        $cleanup();
        $store = $factory();
        $runId = str_repeat('a', 32);
        $plan = new RacePlan(
            $runId,
            2,
            new RequestSpec('POST', '/contract'),
            checkpoints: ['after-read'],
        );

        try {
            $store->healthCheck();
            $store->createRun($plan);
            self::assertEquals($plan, $store->plan($runId));
            self::assertSame([$runId], $store->retainedRunIds());

            $store->markReady($runId, 'p1');
            $store->markReady($runId, 'p2');
            self::assertSame(2, $store->readyCount($runId));

            $store->releaseStart($runId);
            $store->waitForStart($runId, 5);

            $store->reachCheckpoint($runId, 'p1', 'after-read');
            $store->reachCheckpoint($runId, 'p2', 'after-read');
            self::assertSame(2, $store->checkpointCount($runId, 'after-read'));
            $store->releaseCheckpoint($runId, 'after-read');
            $store->waitForCheckpoint($runId, 'after-read', 5);

            $first = new ParticipantResult($runId, 'p1', 200, 10, 20);
            $second = new ParticipantResult($runId, 'p2', 409, 20, 30);
            $store->storeResult($second);
            $store->storeResult($first);
            self::assertEquals([$first, $second], $store->results($runId));
            self::assertSame([
                'run.created',
                'participant.ready',
                'participant.ready',
                'barrier.start_released',
                'checkpoint.reached',
                'checkpoint.reached',
                'checkpoint.released',
                'participant.finished',
                'participant.finished',
            ], array_column(
                array_map(
                    static fn (TimelineEvent $event): array => ['type' => $event->type],
                    $store->timeline($runId)->events,
                ),
                'type',
            ));

            $store->cleanup($runId);
            $store->cleanup($runId);
            self::assertSame([], $store->retainedRunIds());
        } finally {
            $cleanup();
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
