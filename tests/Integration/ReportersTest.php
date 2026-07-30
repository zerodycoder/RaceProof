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
        $projection = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);

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
        self::assertSame([
            'schema_version' => 1,
            'run' => [
                'run_id' => str_repeat('a', 32),
                'outcome' => 'timed_out',
                'expected_participants' => 5,
                'completed_participants' => 4,
                'failed_participants' => 4,
                'statuses' => [200 => 1, 409 => 1],
                'start_spread_ms' => 1.5,
                'duration_ms' => 4,
                'timed_out' => true,
                'artifact_path' => '/tmp/token=[REDACTED]',
            ],
            'participants' => [
                [
                    'participant_id' => 'p1',
                    'outcome' => 'success',
                    'status' => 200,
                    'started_at_ns' => 1_000_000,
                    'finished_at_ns' => 2_000_000,
                    'duration_ms' => 1,
                    'diagnostic' => '',
                    'body' => "<unsafe>\u{FFFD} token=[REDACTED] ".str_repeat('x', 23).' [truncated]',
                    'body_truncated' => true,
                    'headers' => ['authorization' => '[REDACTED]'],
                    'headers_truncated' => true,
                    'exception_class' => null,
                ],
                [
                    'participant_id' => 'p2',
                    'outcome' => 'http_failure',
                    'status' => 409,
                    'started_at_ns' => 1_500_000,
                    'finished_at_ns' => 3_000_000,
                    'duration_ms' => 1.5,
                    'diagnostic' => 'HTTP 409',
                    'body' => '{"conflict":true}',
                    'body_truncated' => false,
                    'headers' => [],
                    'headers_truncated' => false,
                    'exception_class' => null,
                ],
                [
                    'participant_id' => 'p3',
                    'outcome' => 'application_exception',
                    'status' => null,
                    'started_at_ns' => 2_000_000,
                    'finished_at_ns' => 4_000_000,
                    'duration_ms' => 2,
                    'diagnostic' => RuntimeException::class.': password=[REDACTED]',
                    'body' => '',
                    'body_truncated' => false,
                    'headers' => [],
                    'headers_truncated' => false,
                    'exception_class' => RuntimeException::class,
                ],
                [
                    'participant_id' => 'p4',
                    'outcome' => 'worker_error',
                    'status' => null,
                    'started_at_ns' => 2_500_000,
                    'finished_at_ns' => 5_000_000,
                    'duration_ms' => 2.5,
                    'diagnostic' => 'Authorization: [REDACTED]',
                    'body' => '',
                    'body_truncated' => false,
                    'headers' => [],
                    'headers_truncated' => false,
                    'exception_class' => null,
                ],
                [
                    'participant_id' => 'p5',
                    'outcome' => 'missing',
                    'status' => null,
                    'started_at_ns' => 0,
                    'finished_at_ns' => 0,
                    'duration_ms' => 0,
                    'diagnostic' => 'No participant result was recorded.',
                    'body' => '',
                    'body_truncated' => false,
                    'headers' => [],
                    'headers_truncated' => false,
                    'exception_class' => null,
                ],
            ],
            'coordination_summary' => 'ready 1/5; after-read 1/5 blocked',
            'timeline' => [
                'event_count' => 2,
                'events' => [[
                    'type' => 'participant.ready',
                    'occurred_at_ns' => 1,
                    'participant_id' => 'p1',
                    'checkpoint' => null,
                    'data' => ['token' => '[REDACTED]'],
                ]],
                'events_truncated' => true,
                'warning_count' => 2,
                'warnings' => ['token=[REDACTED]'],
                'warnings_truncated' => true,
            ],
        ], $projection);
    }

    public function test_report_projection_preserves_outcomes_natural_order_and_coordination_state(): void
    {
        $runId = str_repeat('b', 32);
        $factory = $this->app->make(RaceReportFactory::class);
        $result = new RaceResult(
            runId: $runId,
            expectedParticipants: 2,
            participants: [
                new ParticipantResult($runId, 'p10', 204, 10, 20),
                new ParticipantResult($runId, 'p2', 201, 5, 15),
                new ParticipantResult($runId, 'p1', 200, 1, 11),
            ],
            timeline: new RaceTimeline($runId, [
                TimelineEvent::make($runId, 'participant.ready', 'p1', occurredAtNs: 1),
                TimelineEvent::make($runId, 'participant.ready', 'p2', occurredAtNs: 2),
                TimelineEvent::make($runId, 'checkpoint.reached', 'p1', 'after-read', occurredAtNs: 3),
                TimelineEvent::make($runId, 'checkpoint.released', checkpoint: 'after-read', occurredAtNs: 4),
            ]),
        );

        $report = $factory->make($result);
        $passed = $factory->make(new RaceResult(
            runId: $runId,
            expectedParticipants: 1,
            participants: [new ParticipantResult($runId, 'p1', 200, 1, 2)],
        ));

        self::assertSame('failed', $report->outcome);
        self::assertSame(['p1', 'p2', 'p10'], array_map(
            static fn (ParticipantReport $participant): string => $participant->participantId,
            $report->participants,
        ));
        self::assertSame('ready 2/2; after-read 1/2 released', $report->coordinationSummary);
        self::assertSame('passed', $passed->outcome);
        self::assertNull($passed->coordinationSummary);
        self::assertSame(0, $passed->timelineEventCount);
        self::assertSame([], $passed->timelineEvents);
        self::assertFalse($passed->timelineEventsTruncated);
        self::assertSame(0, $passed->timelineWarningCount);
        self::assertSame([], $passed->timelineWarnings);
        self::assertFalse($passed->timelineWarningsTruncated);
    }

    public function test_negative_reporting_limits_are_clamped_without_hiding_source_totals(): void
    {
        $runId = str_repeat('c', 32);
        $result = new RaceResult(
            runId: $runId,
            expectedParticipants: 1,
            participants: [new ParticipantResult(
                $runId,
                'p1',
                500,
                1,
                2,
                body: 'response body',
                headers: ['authorization' => 'Bearer secret'],
            )],
            artifactPath: '/tmp/evidence',
            timeline: new RaceTimeline($runId, [
                TimelineEvent::make(
                    $runId,
                    'participant.finished',
                    'p1',
                    data: ['first' => 'one', 'second' => 'two'],
                    occurredAtNs: 1,
                ),
            ], ['warning']),
        );
        $config = $this->app['config'];
        $config->set('raceproof.reporting.response_body_bytes', -1);
        $config->set('raceproof.reporting.header_limit', -1);
        $config->set('raceproof.reporting.diagnostic_text_bytes', -1);
        $config->set('raceproof.reporting.timeline_event_limit', -1);
        $config->set('raceproof.reporting.timeline_warning_limit', -1);

        $bounded = $this->app->make(RaceReportFactory::class)->make($result);

        self::assertSame('', $bounded->artifactPath);
        self::assertSame([], $bounded->timelineEvents);
        self::assertSame(1, $bounded->timelineEventCount);
        self::assertTrue($bounded->timelineEventsTruncated);
        self::assertSame([], $bounded->timelineWarnings);
        self::assertSame(1, $bounded->timelineWarningCount);
        self::assertTrue($bounded->timelineWarningsTruncated);
        self::assertSame('', $bounded->participants[0]->diagnostic);
        self::assertSame('', $bounded->participants[0]->body);
        self::assertTrue($bounded->participants[0]->bodyTruncated);
        self::assertSame([], $bounded->participants[0]->headers);
        self::assertTrue($bounded->participants[0]->headersTruncated);

        $config->set('raceproof.reporting.timeline_event_limit', 1);
        $config->set('raceproof.reporting.timeline_event_data_limit', -1);

        $eventBounded = $this->app->make(RaceReportFactory::class)->make($result);

        self::assertCount(1, $eventBounded->timelineEvents);
        self::assertSame([], $eventBounded->timelineEvents[0]['data']);
        self::assertFalse($eventBounded->timelineEventsTruncated);
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
                        data: ['token' => 'event-secret', 'safe' => 'visible'],
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
