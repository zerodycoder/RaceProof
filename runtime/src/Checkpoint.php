<?php

declare(strict_types=1);

namespace RaceProof\Runtime;

use LogicException;
use RaceProof\Runtime\Contracts\CheckpointHandler;

final class Checkpoint
{
    private static ?CheckpointHandler $handler = null;

    private static ?CheckpointActivation $activation = null;

    public static function sync(string $name, ?int $timeoutMs = null): void
    {
        self::$handler?->sync($name, $timeoutMs);
    }

    public static function active(): bool
    {
        return self::$handler !== null;
    }

    /** @internal Called only by the RaceProof worker command after plan validation. */
    public static function activate(CheckpointHandler $handler): CheckpointActivation
    {
        if (self::$handler !== null) {
            throw new LogicException('A RaceProof checkpoint handler is already active in this process.');
        }

        $activation = new CheckpointActivation(bin2hex(random_bytes(16)));
        self::$handler = $handler;
        self::$activation = $activation;

        return $activation;
    }

    /** @internal Requires the exact process-local capability returned by activate(). */
    public static function deactivate(CheckpointActivation $activation): void
    {
        if (self::$activation !== $activation) {
            throw new LogicException('The RaceProof checkpoint activation capability is invalid.');
        }

        self::$handler = null;
        self::$activation = null;
    }

    private function __construct() {}
}
