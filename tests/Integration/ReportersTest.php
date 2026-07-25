<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use DOMDocument;
use DOMXPath;
use RaceProof\Laravel\Contracts\Reporter;
use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Reports\HumanReporter;
use RaceProof\Laravel\Reports\JsonReporter;
use RaceProof\Laravel\Reports\JUnitReporter;
use RaceProof\Laravel\Reports\ParticipantReport;
use RaceProof\Laravel\Reports\RaceReport;
use RaceProof\Laravel\Reports\RaceReportFactory;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Results\RaceTimeline;
use RuntimeException;

final class ReportersTest extends TestCase
{
    public function test_the_stable_report_model_is_bounded_redacted_and_accounts_for_missing_participants(): void
    {
        $this->configureSmallLimits();
        $report = $this->app->make(RaceReportFactory::class)->make($this->raceResult());
        $serialized = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame(RaceReport::SCHEMA_VERSION, $report->schemaVersion);
        self::assertSame('timed_out', $report->outcome);
        self::assertSame(5, $report->expectedParticipants);
        self::assertSame(4, $report->completedParticipants);
        self::assertSame(4, $report->failedParticipants);
        self::assertSame([200 => 1, 409 => 1], $report->statuses);
        self::assertCount(5, $report->participants);
        self::assertSame(ParticipantReport::OUTCOME_MISSING, $report->participants[4]->outcome);
        self::assertSame('No participant result was recorded.', $report->participants[4]->diagnostic);
        self::assertTrue($report->participants[0]->bodyTruncated);
        self::assertTrue($report->participants[0]->headersTruncated);
        self::assertSame('[REDACTED]', $report->participants[0]->headers['authorization']);
        self::assertSame(2, $report->timelineWarningCount);
        self::assertCount(1, $report->timelineWarnings);
        self::assertTrue($report->timelineWarningsTruncated);
        self::assertSame(2, $report->timelineEventCount);
        self::assertCount(1, $report->timelineEvents);
        self::assertTrue($report->timelineEventsTruncated);
        self::assertSame('[REDACTED]', $report->timelineEvents[0]['data']['token']);
        self::assertStringNotContainsString('body-secret', $serialized);
        self::assertStringNotContainsString('header-secret', $serialized);
        self::assertStringNotContainsString('worker-secret', $serialized);
        self::assertStringNotContainsString('exception-secret', $serialized);
        self::assertStringNotContainsString('event-secret', $serialized);
        self::assertStringNotContainsString('warning-secret', $serialized);
        self::assertStringContainsString('[REDACTED]', $serialized);
    }

    public function test_human_and_json_reporters_share_the_model_contract_and_remain_cli_friendly(): void
    {
        $this->configureSmallLimits();
        $result = $this->raceResult();
        $humanReporter = $this->app->make(HumanReporter::class);
        $jsonReporter = $this->app->make(JsonReporter::class);

        self::assertInstanceOf(Reporter::class, $humanReporter);
        self::assertInstanceOf(Reporter::class, $jsonReporter);

        $human = $result->report($humanReporter);
        $json = $result->report($jsonReporter);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(320, strlen($human));
        self::assertStringEndsWith('[truncated]', $human);
        self::assertStringContainsString('RaceProof report v1', $human);
        self::assertStringNotContainsString('worker-secret', $human);
        self::assertStringEndsWith("\n", $json);
        self::assertSame(1, $decoded['schema_version']);
        self::assertSame('timed_out', $decoded['run']['outcome']);
        self::assertSame('missing', $decoded['participants'][4]['outcome']);
        self::assertCount(1, $decoded['timeline']['events']);
        self::assertTrue($decoded['timeline']['events_truncated']);
        self::assertIsArray($decoded['timeline']['warnings']);
        self::assertStringNotContainsString('body-secret', $json);
    }

    public function test_junit_report_is_valid_xml_with_honest_failure_error_and_timeout_counts(): void
    {
        $this->configureSmallLimits();
        $reporter = $this->app->make(JUnitReporter::class);
        $xml = $this->raceResult()->report($reporter);
        $document = new DOMDocument;

        self::assertInstanceOf(Reporter::class, $reporter);
        self::assertTrue($document->loadXML($xml));

        $xpath = new DOMXPath($document);
        $suite = $xpath->query('/testsuites/testsuite')->item(0);

        self::assertNotNull($suite);
        self::assertSame('6', $suite->attributes?->getNamedItem('tests')?->nodeValue);
        self::assertSame('1', $suite->attributes?->getNamedItem('failures')?->nodeValue);
        self::assertSame('4', $suite->attributes?->getNamedItem('errors')?->nodeValue);
        self::assertSame(6, $xpath->query('//testcase')->length);
        self::assertSame(1, $xpath->query('//failure[@type="http_status"]')->length);
        self::assertSame(1, $xpath->query('//error[@type="missing"]')->length);
        self::assertSame(1, $xpath->query('//testcase[@name="timeout"]/error')->length);
        self::assertSame('1', $xpath->evaluate('string(//property[@name="raceproof.schema_version"]/@value)'));
        self::assertStringNotContainsString('header-secret', $xml);
        self::assertStringNotContainsString('exception-secret', $xml);
        self::assertStringNotContainsString("\x01", $xml);
        self::assertStringContainsString('&lt;unsafe&gt;', $xml);
    }

    private function configureSmallLimits(): void
    {
        $this->app['config']->set('raceproof.reporting.human_output_bytes', 320);
        $this->app['config']->set('raceproof.reporting.diagnostic_text_bytes', 96);
        $this->app['config']->set('raceproof.reporting.response_body_bytes', 64);
        $this->app['config']->set('raceproof.reporting.header_limit', 1);
        $this->app['config']->set('raceproof.reporting.timeline_event_limit', 1);
        $this->app['config']->set('raceproof.reporting.timeline_event_data_limit', 1);
        $this->app['config']->set('raceproof.reporting.timeline_warning_limit', 1);
    }

    private function raceResult(): RaceResult
    {
        $runId = str_repeat('a', 32);

        return new RaceResult(
            runId: $runId,
            expectedParticipants: 5,
            participants: [
                new ParticipantResult(
                    $runId,
                    'p1',
                    200,
                    1_000_000,
                    2_000_000,
                    body: '<unsafe>'."\x01".' token=body-secret '.str_repeat('x', 100),
                    headers: [
                        'authorization' => 'Bearer header-secret',
                        'x-request-id' => 'request-1',
                    ],
                ),
                new ParticipantResult(
                    $runId,
                    'p2',
                    409,
                    1_500_000,
                    3_000_000,
                    body: '{"conflict":true}',
                ),
                new ParticipantResult(
                    $runId,
                    'p3',
                    null,
                    2_000_000,
                    4_000_000,
                    exceptionClass: RuntimeException::class,
                    exceptionMessage: 'password=exception-secret',
                ),
                new ParticipantResult(
                    $runId,
                    'p4',
                    null,
                    2_500_000,
                    5_000_000,
                    workerError: 'Authorization: Bearer worker-secret',
                ),
            ],
            timedOut: true,
            artifactPath: '/tmp/token=artifact-secret',
            timeline: new RaceTimeline(
                $runId,
                [
                    TimelineEvent::make(
                        $runId,
                        'participant.ready',
                        'p1',
                        data: ['token' => 'event-secret'],
                        occurredAtNs: 1,
                    ),
                    TimelineEvent::make($runId, 'checkpoint.reached', 'p1', 'after-read', occurredAtNs: 2),
                ],
                [
                    'token=warning-secret',
                    'second warning',
                ],
            ),
        );
    }
}
