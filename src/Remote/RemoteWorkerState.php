<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Remote;

use RaceProof\Laravel\Exceptions\RaceProofException;

/**
 * @internal
 */
final readonly class RemoteWorkerState
{
    private const TERMINAL = ['completed', 'failed', 'stopped', 'cancelled'];

    public function __construct(
        public string $status,
        public string $agentId,
        public string $runId,
        public string $participantId,
        public int $expiresAtMs,
        public ?int $exitCode = null,
        public string $output = '',
        public string $errorOutput = '',
    ) {
        if (! in_array($status, ['pending', 'claimed', 'running', 'stop_requested', ...self::TERMINAL], true)) {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
        }
    }

    /** @param array<string, string> $state */
    public static function fromArray(array $state, int $outputBytes): self
    {
        $expectedFields = [
            'agent_id',
            'error_output',
            'expires_at_ms',
            'output',
            'participant_id',
            'run_id',
            'status',
        ];

        if (isset($state['exit_code'])) {
            $expectedFields[] = 'exit_code';
            sort($expectedFields);
        }

        $actualFields = array_keys($state);
        sort($actualFields);
        $exitCode = null;

        if (isset($state['exit_code'])) {
            $validatedExitCode = filter_var($state['exit_code'], FILTER_VALIDATE_INT);

            if ($validatedExitCode === false) {
                throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
            }

            $exitCode = $validatedExitCode;
        }

        $expiresAtMs = isset($state['expires_at_ms'])
            ? filter_var($state['expires_at_ms'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ])
            : false;

        if (
            $actualFields !== $expectedFields
            || $expiresAtMs === false
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/D', $state['agent_id']) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $state['run_id']) !== 1
            || preg_match('/^p(?:[1-9]|[1-9][0-9]|100)$/D', $state['participant_id']) !== 1
            || strlen($state['output'] ?? '') > $outputBytes
            || strlen($state['error_output'] ?? '') > $outputBytes
        ) {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
        }

        $worker = new self(
            $state['status'],
            $state['agent_id'],
            $state['run_id'],
            $state['participant_id'],
            $expiresAtMs,
            $exitCode,
            $state['output'],
            $state['error_output'],
        );

        if ($worker->terminal() !== ($worker->exitCode !== null)) {
            throw new RaceProofException('RaceProof remote worker control plane returned invalid state.');
        }

        return $worker;
    }

    public function terminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
