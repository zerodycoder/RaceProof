<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Support;

use RaceProof\Laravel\Exceptions\InvalidRacePlan;

final class ParticipantId
{
    public static function number(string $participantId): int
    {
        if (! preg_match('/^p([1-9]|[1-9][0-9]|100)$/', $participantId, $matches)) {
            throw new InvalidRacePlan(
                "Invalid participant ID [{$participantId}]; expected p1 through p100.",
            );
        }

        return (int) $matches[1];
    }
}
