<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Reports;

use RaceProof\Laravel\Contracts\Reporter;
use RaceProof\Laravel\Results\RaceResult;

final readonly class JUnitReporter implements Reporter
{
    public function __construct(private RaceReportFactory $factory) {}

    public function report(RaceResult $result): string
    {
        $report = $this->factory->make($result);
        $testCases = [];
        $failures = 0;
        $errors = 0;

        foreach ($report->participants as $participant) {
            $testCases[] = $this->testCase($participant);

            if ($participant->error()) {
                $errors++;
            } elseif ($participant->failed()) {
                $failures++;
            }
        }

        if ($report->timedOut) {
            $testCases[] = $this->timeoutTestCase($report);
            $errors++;
        }

        $tests = count($testCases);
        $time = $this->seconds($report->durationMs);
        $runName = 'RaceProof '.$report->runId;
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<testsuites name="RaceProof" tests="%d" failures="%d" errors="%d" time="%s">',
                $tests,
                $failures,
                $errors,
                $time,
            ),
            sprintf(
                '  <testsuite name="%s" tests="%d" failures="%d" errors="%d" time="%s">',
                $this->xml($runName),
                $tests,
                $failures,
                $errors,
                $time,
            ),
            '    <properties>',
            '      <property name="raceproof.schema_version" value="'.RaceReport::SCHEMA_VERSION.'"/>',
            '      <property name="raceproof.outcome" value="'.$this->xml($report->outcome).'"/>',
            '      <property name="raceproof.expected_participants" value="'.$report->expectedParticipants.'"/>',
            '      <property name="raceproof.completed_participants" value="'.$report->completedParticipants.'"/>',
            '      <property name="raceproof.timeline_events" value="'.$report->timelineEventCount.'"/>',
            '      <property name="raceproof.timeline_warnings" value="'.$report->timelineWarningCount.'"/>',
            '    </properties>',
            ...$testCases,
            '  </testsuite>',
            '</testsuites>',
        ];

        return implode("\n", $lines)."\n";
    }

    private function testCase(ParticipantReport $participant): string
    {
        $lines = [
            sprintf(
                '    <testcase classname="RaceProof.Participant" name="%s" time="%s">',
                $this->xml($participant->participantId),
                $this->seconds($participant->durationMs),
            ),
        ];

        if ($participant->outcome === ParticipantReport::OUTCOME_HTTP_FAILURE) {
            $lines[] = sprintf(
                '      <failure type="http_status" message="%s">%s</failure>',
                $this->xml($participant->diagnostic),
                $this->xml($participant->diagnostic),
            );
        } elseif ($participant->error()) {
            $type = $participant->exceptionClass ?? $participant->outcome;
            $lines[] = sprintf(
                '      <error type="%s" message="%s">%s</error>',
                $this->xml($type),
                $this->xml($participant->diagnostic),
                $this->xml($participant->diagnostic),
            );
        }

        $output = $this->participantOutput($participant);

        if ($output !== '') {
            $lines[] = '      <system-out>'.$this->xml($output).'</system-out>';
        }

        $lines[] = '    </testcase>';

        return implode("\n", $lines);
    }

    private function timeoutTestCase(RaceReport $report): string
    {
        $message = 'Race run timed out before clean completion.';

        return implode("\n", [
            '    <testcase classname="RaceProof.Run" name="timeout" time="'.$this->seconds($report->durationMs).'">',
            '      <error type="timeout" message="'.$this->xml($message).'">'.$this->xml($message).'</error>',
            '    </testcase>',
        ]);
    }

    private function participantOutput(ParticipantReport $participant): string
    {
        $evidence = [];

        if ($participant->headers !== []) {
            $evidence[] = 'Headers: '.json_encode(
                $participant->headers,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        }

        if ($participant->headersTruncated) {
            $evidence[] = 'Additional headers omitted by the report limit.';
        }

        if ($participant->body !== '') {
            $evidence[] = 'Body: '.$participant->body;
        }

        if ($participant->bodyTruncated) {
            $evidence[] = 'Response body truncated by the report limit.';
        }

        return implode("\n", $evidence);
    }

    private function seconds(float $milliseconds): string
    {
        return number_format(max(0.0, $milliseconds) / 1_000, 6, '.', '');
    }

    private function xml(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            "\u{FFFD}",
            $value,
        ) ?? '[invalid XML text]';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
