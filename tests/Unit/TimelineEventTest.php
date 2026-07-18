<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceProofException;

final class TimelineEventTest extends TestCase
{
    public function test_it_round_trips_the_versioned_event_schema(): void
    {
        $event = TimelineEvent::make(
            runId: str_repeat('a', 32),
            type: 'checkpoint.reached',
            participantId: 'p2',
            checkpoint: 'after-read',
            data: ['attempt' => 1, 'released' => false],
            occurredAtNs: 123,
        );

        $decoded = json_decode(json_encode($event, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = TimelineEvent::fromArray($decoded);

        self::assertEquals($event, $restored);
        self::assertSame(TimelineEvent::SCHEMA_VERSION, $decoded['schema_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $event->eventId);
    }

    public function test_it_rejects_unsupported_versions(): void
    {
        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('Unsupported timeline schema version');

        TimelineEvent::fromArray([
            'schema_version' => 2,
            'event_id' => str_repeat('b', 32),
            'run_id' => str_repeat('a', 32),
            'type' => 'run.created',
            'occurred_at_ns' => 1,
            'participant_id' => null,
            'checkpoint' => null,
            'data' => [],
        ]);
    }

    public function test_it_rejects_nested_unbounded_event_data(): void
    {
        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('scalar JSON values');

        TimelineEvent::make(str_repeat('a', 32), 'run.invalid', data: [
            'nested' => ['secret' => 'value'],
        ]);
    }
}
