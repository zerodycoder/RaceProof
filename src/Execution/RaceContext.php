<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Execution;

use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Data\RacePlan;

final class RaceContext
{
    private ?RacePlan $plan = null;

    private ?CoordinatorStore $store = null;

    private ?string $participantId = null;

    public function activate(RacePlan $plan, string $participantId, CoordinatorStore $store): void
    {
        $this->plan = $plan;
        $this->participantId = $participantId;
        $this->store = $store;
    }

    public function active(): bool
    {
        return $this->plan !== null && $this->store !== null && $this->participantId !== null;
    }

    public function plan(): ?RacePlan
    {
        return $this->plan;
    }

    public function store(): ?CoordinatorStore
    {
        return $this->store;
    }

    public function participantId(): ?string
    {
        return $this->participantId;
    }

    public function clear(): void
    {
        $this->plan = null;
        $this->participantId = null;
        $this->store = null;
    }
}
