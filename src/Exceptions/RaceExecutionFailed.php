<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Exceptions;

use RaceProof\Laravel\Results\RaceResult;
use Throwable;

final class RaceExecutionFailed extends RaceProofException
{
    /** @internal RaceOrchestrator creates this exception with retained evidence. */
    public function __construct(
        string $message,
        public readonly RaceResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message."\n\n".$result->failureReport(), 0, $previous);
    }
}
