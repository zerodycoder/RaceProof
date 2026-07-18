<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Coordination\FileCoordinatorStore;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Data\RequestSpec;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Support\RunId;
use Symfony\Component\Process\Process;

final class FileCoordinatorTimelineTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = dirname(__DIR__, 2).'/build/timeline-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_preserves_valid_events_when_a_partial_line_is_malformed(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $plan = $this->plan();
        $store->createRun($plan);
        $store->recordEvent(TimelineEvent::make($plan->runId, 'run.note', data: ['message' => 'safe']));
        file_put_contents(
            $this->basePath.'/'.$plan->runId.'/timeline.jsonl',
            '{"schema_version":1,"event_id":"partial',
            FILE_APPEND | LOCK_EX,
        );

        $timeline = $store->timeline($plan->runId);

        self::assertSame(['run.created', 'run.note'], array_map(
            static fn (TimelineEvent $event): string => $event->type,
            $timeline->events,
        ));
        self::assertSame(['Timeline line 3 is malformed and was ignored.'], $timeline->warnings);

        $json = json_decode(json_encode($timeline, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['schema_version']);
        self::assertCount(2, $json['events']);
    }

    public function test_concurrent_writers_produce_complete_independent_json_lines(): void
    {
        $store = new FileCoordinatorStore($this->basePath);
        $plan = $this->plan();
        $store->createRun($plan);
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $script = <<<'PHP'
require $argv[1];
$store = new RaceProof\Laravel\Coordination\FileCoordinatorStore($argv[2]);
$store->recordEvent(RaceProof\Laravel\Data\TimelineEvent::make(
    $argv[3],
    'participant.concurrent_write',
    $argv[4],
    data: ['writer' => $argv[4]],
));
PHP;
        $processes = [];

        for ($number = 1; $number <= 12; $number++) {
            $participantId = 'p'.$number;
            $processes[] = new Process([
                PHP_BINARY,
                '-r',
                $script,
                $autoload,
                $this->basePath,
                $plan->runId,
                $participantId,
            ]);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
        }

        $timeline = $store->timeline($plan->runId);
        $concurrent = $timeline->ofType('participant.concurrent_write');

        self::assertCount(12, $concurrent);
        self::assertSame([], $timeline->warnings);
        self::assertCount(13, $timeline->events);
        self::assertCount(12, array_unique(array_map(
            static fn (TimelineEvent $event): string => $event->eventId,
            $concurrent,
        )));
    }

    private function plan(): RacePlan
    {
        return new RacePlan(RunId::generate(), 12, new RequestSpec('POST', '/checkout'));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
