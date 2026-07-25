<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Results;

use JsonSerializable;
use RaceProof\Laravel\Data\TimelineEvent;

final readonly class RaceTimeline implements JsonSerializable
{
    /**
     * @internal RaceProof reconstructs timelines from retained evidence.
     *
     * @param  list<TimelineEvent>  $events
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $runId,
        public array $events = [],
        public array $warnings = [],
    ) {}

    /** @return list<TimelineEvent> */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (TimelineEvent $event): bool => $event->type === $type,
        ));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => TimelineEvent::SCHEMA_VERSION,
            'run_id' => $this->runId,
            'events' => $this->events,
            'warnings' => $this->warnings,
        ];
    }
}
