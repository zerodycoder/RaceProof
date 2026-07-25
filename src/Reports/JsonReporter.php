<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use RaceProof\Laravel\Contracts\Reporter;
use RaceProof\Laravel\Results\RaceResult;

final readonly class JsonReporter implements Reporter
{
    /** @internal Resolve reporters through Laravel's container. */
    public function __construct(private RaceReportFactory $factory) {}

    public function report(RaceResult $result): string
    {
        return json_encode(
            $this->factory->make($result),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n";
    }
}
