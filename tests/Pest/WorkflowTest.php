<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('drives three real Laravel workers through a checkpoint', function (): void {
    $script = dirname(__DIR__).'/Fixtures/app/run-race.php';
    $process = new Process([PHP_BINARY, $script], dirname(__DIR__, 2), timeout: 30);
    $process->mustRun();

    /** @var array<string, mixed> $result */
    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result)
        ->expected_participants->toBe(3)
        ->timed_out->toBeFalse()
        ->artifact_path->toBeNull()
        ->and($result['participants'])->toHaveCount(3)
        ->and($result['statuses'])->toBe(['200' => 3]);

    foreach ($result['participants'] as $participant) {
        expect($participant)
            ->status->toBe(200)
            ->worker_error->toBeNull();
    }
});
