<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Coordination;

use FilesystemIterator;
use JsonException;
use JsonSerializable;
use RaceProof\Laravel\Contracts\CoordinatorStore;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\RacePlan;
use RaceProof\Laravel\Exceptions\CoordinationTimeout;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\Clock;
use RaceProof\Laravel\Support\Input;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class FileCoordinatorStore implements CoordinatorStore
{
    public function __construct(private readonly string $basePath) {}

    public function createRun(RacePlan $plan): void
    {
        $directory = $this->runDirectory($plan->runId);
        $this->makeDirectory($directory.'/participants');
        $this->makeDirectory($directory.'/checkpoints');
        $this->atomicWrite($directory.'/plan.json', $this->encode($plan));
    }

    public function plan(string $runId): RacePlan
    {
        $path = $this->runDirectory($runId).'/plan.json';
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RaceProofException("Race plan [{$runId}] does not exist.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return RacePlan::fromArray(Input::mapValue($decoded, 'plan'));
        } catch (JsonException $exception) {
            throw new RaceProofException("Race plan [{$runId}] contains invalid JSON.", 0, $exception);
        }
    }

    public function markReady(string $runId, string $participantId): void
    {
        $this->atomicWrite($this->participantPath($runId, $participantId, 'ready'), 'ready');
    }

    public function readyCount(string $runId): int
    {
        return count(glob($this->runDirectory($runId).'/participants/*.ready') ?: []);
    }

    public function releaseStart(string $runId): void
    {
        $this->atomicWrite($this->runDirectory($runId).'/start.release', (string) Clock::nowNs());
    }

    public function waitForStart(string $runId, int $timeoutMs): void
    {
        $this->waitForFile(
            $this->runDirectory($runId).'/start.release',
            $timeoutMs,
            "Timed out waiting for the start barrier for run [{$runId}].",
        );
    }

    public function reachCheckpoint(string $runId, string $participantId, string $checkpoint): void
    {
        $directory = $this->checkpointDirectory($runId, $checkpoint);
        $this->makeDirectory($directory);
        $this->atomicWrite($directory.'/'.$this->safeParticipant($participantId).'.reached', (string) Clock::nowNs());
    }

    public function checkpointCount(string $runId, string $checkpoint): int
    {
        return count(glob($this->checkpointDirectory($runId, $checkpoint).'/*.reached') ?: []);
    }

    public function releaseCheckpoint(string $runId, string $checkpoint): void
    {
        $directory = $this->checkpointDirectory($runId, $checkpoint);
        $this->makeDirectory($directory);
        $this->atomicWrite($directory.'/release', (string) Clock::nowNs());
    }

    public function waitForCheckpoint(string $runId, string $checkpoint, int $timeoutMs): void
    {
        $this->waitForFile(
            $this->checkpointDirectory($runId, $checkpoint).'/release',
            $timeoutMs,
            "Timed out waiting for checkpoint [{$checkpoint}] in run [{$runId}].",
        );
    }

    public function storeResult(ParticipantResult $result): void
    {
        $this->atomicWrite(
            $this->participantPath($result->runId, $result->participantId, 'result.json'),
            $this->encode($result),
        );
    }

    public function results(string $runId): array
    {
        $results = [];

        foreach (glob($this->runDirectory($runId).'/participants/*.result.json') ?: [] as $path) {
            try {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    $results[] = ParticipantResult::fromArray(Input::mapValue($decoded, 'participant result'));
                }
            } catch (JsonException) {
                // The orchestrator reports a missing result as a worker failure.
            }
        }

        usort($results, fn (ParticipantResult $a, ParticipantResult $b): int => $a->participantId <=> $b->participantId);

        return $results;
    }

    public function cleanup(string $runId): void
    {
        $directory = $this->runDirectory($runId);

        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                throw new RaceProofException('Unexpected coordinator directory entry.');
            }

            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    private function participantPath(string $runId, string $participantId, string $suffix): string
    {
        return $this->runDirectory($runId).'/participants/'.$this->safeParticipant($participantId).'.'.$suffix;
    }

    private function checkpointDirectory(string $runId, string $checkpoint): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $checkpoint)) {
            throw new RaceProofException("Invalid checkpoint name [{$checkpoint}].");
        }

        return $this->runDirectory($runId).'/checkpoints/'.$checkpoint;
    }

    private function runDirectory(string $runId): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new RaceProofException('Invalid run ID.');
        }

        return rtrim($this->basePath, '/\\').'/'.$runId;
    }

    private function safeParticipant(string $participantId): string
    {
        if (! preg_match('/^p[1-9][0-9]{0,2}$/', $participantId)) {
            throw new RaceProofException("Invalid participant ID [{$participantId}].");
        }

        return $participantId;
    }

    private function waitForFile(string $path, int $timeoutMs, string $message): void
    {
        $deadline = Clock::nowNs() + ($timeoutMs * 1_000_000);

        while (! is_file($path)) {
            if (Clock::nowNs() >= $deadline) {
                throw new CoordinationTimeout($message);
            }

            usleep(2_000);
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create RaceProof directory [{$directory}].");
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $this->makeDirectory(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RaceProofException("Unable to write RaceProof file [{$temporary}].");
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $path)) {
            @unlink($path);
            if (! @rename($temporary, $path)) {
                @unlink($temporary);
                throw new RaceProofException("Unable to publish RaceProof file [{$path}].");
            }
        }
    }

    private function encode(JsonSerializable $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RaceProofException('Unable to encode RaceProof data.', 0, $exception);
        }
    }
}
