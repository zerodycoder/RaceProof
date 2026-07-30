<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use Illuminate\Contracts\Config\Repository as Config;
use RaceProof\Laravel\Contracts\Reporter;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Support\ConfigValue;
use RaceProof\Laravel\Support\SensitiveDataRedactor;

final readonly class HumanReporter implements Reporter
{
    /** @internal Resolve reporters through Laravel's container. */
    public function __construct(
        private RaceReportFactory $factory,
        private Config $config,
        private SensitiveDataRedactor $redactor,
    ) {}

    public function report(RaceResult $result): string
    {
        $report = $this->factory->make($result);
        $lines = [
            "RaceProof report v{$report->schemaVersion} - {$report->runId}",
            sprintf(
                'Outcome: %s; participants: %d/%d completed; %d failed; timed out: %s.',
                $report->outcome,
                $report->completedParticipants,
                $report->expectedParticipants,
                $report->failedParticipants,
                $report->timedOut ? 'yes' : 'no',
            ),
            sprintf(
                'Timing: %.2f ms total; %.2f ms start spread.',
                $report->durationMs,
                $report->startSpreadMs,
            ),
        ];
        $statuses = [];

        foreach ($report->statuses as $status => $count) {
            $statuses[] = "{$status} x {$count}";
        }

        if ($statuses !== []) {
            $lines[] = 'Statuses: '.implode(', ', $statuses).'.';
        }

        if ($report->coordinationSummary !== null) {
            $lines[] = 'Coordination: '.$report->coordinationSummary.'.';
        }

        foreach ($report->participants as $participant) {
            if ($participant->failed()) {
                $lines[] = sprintf(
                    'Failure %s [%s%s]: %s',
                    $participant->participantId,
                    $participant->outcome,
                    $participant->execution === 'queue'
                        ? sprintf(
                            ', queue %s, %d attempt(s)',
                            $participant->jobClass ?? 'unknown job',
                            $participant->attempts,
                        )
                        : '',
                    $participant->diagnostic,
                );
            }
        }

        $lines[] = sprintf(
            'Timeline: %d event(s); %d warning(s)%s.',
            $report->timelineEventCount,
            $report->timelineWarningCount,
            $report->timelineWarningsTruncated ? ' (warning details truncated)' : '',
        );
        $lines[] = 'Artifacts: '.($report->artifactPath ?? 'none (successful run was cleaned)').'.';
        $limit = max(0, ConfigValue::integer(
            $this->config,
            'raceproof.reporting.human_output_bytes',
            16_384,
        ));

        return $this->redactor->bounded(implode("\n", $lines), $limit);
    }
}
