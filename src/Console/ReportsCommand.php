<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Console;

use Illuminate\Console\Command;
use RaceProof\Laravel\Studio\ReportArchive;
use RaceProof\Laravel\Studio\StudioRun;

final class ReportsCommand extends Command
{
    protected $signature = 'raceproof:reports
        {run? : A 32-character RaceProof run ID}
        {--json : Print the archived JSON document}';

    protected $description = 'List or inspect bounded, redacted RaceProof Studio reports';

    public function __construct(private readonly ReportArchive $archive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->archive->available()) {
            $this->components->error(
                'RaceProof Studio is unavailable. Enable it explicitly in a local or testing environment.',
            );

            return self::FAILURE;
        }

        $runId = $this->argument('run');

        if ($runId === null) {
            return $this->listing();
        }

        if (! is_string($runId)) {
            $this->components->error('The run ID must be a string.');

            return self::INVALID;
        }

        $run = $this->archive->find($runId);

        if ($run === null) {
            $this->components->error("Studio report not found: {$runId}");

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->output->write(json_encode(
                $run,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Run', $run->runId);
        $this->components->twoColumnDetail('Captured', $run->capturedAt);
        $this->components->twoColumnDetail('Outcome', $run->outcome);
        $this->components->twoColumnDetail(
            'Participants',
            "{$run->completedParticipants}/{$run->expectedParticipants}",
        );
        $this->components->twoColumnDetail('Duration', sprintf('%.2f ms', $run->durationMs));
        $this->components->twoColumnDetail('Start spread', sprintf('%.2f ms', $run->startSpreadMs));
        $this->newLine();
        $this->table(
            ['Participant', 'Outcome', 'Status', 'Duration'],
            array_map(
                static fn ($participant): array => [
                    $participant->id,
                    $participant->outcome,
                    $participant->status === null ? '—' : (string) $participant->status,
                    sprintf('%.2f ms', $participant->durationMs),
                ],
                $run->participants,
            ),
        );

        return self::SUCCESS;
    }

    private function listing(): int
    {
        $runs = $this->archive->all();

        if ($runs === []) {
            $this->components->info('No RaceProof Studio reports have been retained.');

            return self::SUCCESS;
        }

        $this->table(
            ['Run', 'Outcome', 'Participants', 'Duration', 'Captured'],
            array_map(
                static fn (StudioRun $run): array => [
                    $run->runId,
                    $run->outcome,
                    "{$run->completedParticipants}/{$run->expectedParticipants}",
                    sprintf('%.2f ms', $run->durationMs),
                    $run->capturedAt,
                ],
                $runs,
            ),
        );

        return self::SUCCESS;
    }
}
