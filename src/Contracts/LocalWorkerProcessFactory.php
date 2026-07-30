<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Contracts;

/**
 * @internal Remote agents always launch the package's fixed local worker command.
 */
interface LocalWorkerProcessFactory extends WorkerTransport {}
