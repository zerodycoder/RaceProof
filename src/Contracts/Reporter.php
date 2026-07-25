<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

use RaceProof\Laravel\Results\RaceResult;

interface Reporter
{
    public function report(RaceResult $result): string;
}
