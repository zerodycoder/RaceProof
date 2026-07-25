<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Results\RaceTimeline;
use RaceProof\Laravel\Studio\ReportArchive;

final class StudioRoutesTest extends TestCase
{
    private string $archivePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('raceproof.studio.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = dirname(__DIR__, 2).'/build/studio-route-tests/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.studio.path', $this->archivePath);
        $this->app->forgetInstance(ReportArchive::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->archivePath);

        parent::tearDown();
    }

    public function test_dashboard_lists_and_renders_retained_run_evidence_with_security_headers(): void
    {
        $runId = str_repeat('a', 32);
        $this->app->make(ReportArchive::class)->store($this->raceResult($runId));

        $index = $this->get('/raceproof');

        $index
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('Make every race visible.')
            ->assertSee($runId)
            ->assertDontSee('response-secret');

        $show = $this->get('/raceproof/runs/'.$runId);

        $show
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertSee('Execution lanes')
            ->assertSee('Participant outcomes')
            ->assertSee('stock-read')
            ->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertDontSee('response-secret');
    }

    public function test_dashboard_downloads_the_validated_redacted_archive_and_returns_404_for_missing_runs(): void
    {
        $runId = str_repeat('b', 32);
        $this->app->make(ReportArchive::class)->store($this->raceResult($runId));

        $download = $this->get('/raceproof/runs/'.$runId.'/report.json');

        $download
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="raceproof-'.$runId.'.json"')
            ->assertJsonPath('archive_schema', 1)
            ->assertJsonPath('report.run.run_id', $runId)
            ->assertJsonMissing(['response-secret']);

        $this->get('/raceproof/runs/'.str_repeat('c', 32))->assertNotFound();
        $this->get('/raceproof/runs/'.str_repeat('c', 32).'/report.json')->assertNotFound();
    }

    public function test_dashboard_rejects_direct_clients_outside_the_explicit_ip_allowlist(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->get('/raceproof')
            ->assertForbidden()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('explicitly allowed direct client addresses');
    }

    private function raceResult(string $runId): RaceResult
    {
        return new RaceResult(
            runId: $runId,
            expectedParticipants: 2,
            participants: [
                new ParticipantResult(
                    runId: $runId,
                    participantId: 'p1',
                    status: 201,
                    startedAtNs: 1_000_000,
                    finishedAtNs: 2_000_000,
                    body: '<script>alert("unsafe")</script> password=response-secret',
                ),
                new ParticipantResult(
                    runId: $runId,
                    participantId: 'p2',
                    status: 409,
                    startedAtNs: 1_050_000,
                    finishedAtNs: 2_200_000,
                    body: '{"conflict":true}',
                ),
            ],
            timeline: new RaceTimeline($runId, [
                TimelineEvent::make($runId, 'participant.ready', 'p1', occurredAtNs: 900_000),
                TimelineEvent::make($runId, 'participant.ready', 'p2', occurredAtNs: 920_000),
                TimelineEvent::make(
                    $runId,
                    'checkpoint.reached',
                    'p1',
                    'stock-read',
                    occurredAtNs: 1_200_000,
                ),
                TimelineEvent::make(
                    $runId,
                    'checkpoint.released',
                    checkpoint: 'stock-read',
                    occurredAtNs: 1_300_000,
                ),
                TimelineEvent::make($runId, 'participant.finished', 'p1', occurredAtNs: 2_000_000),
                TimelineEvent::make($runId, 'participant.finished', 'p2', occurredAtNs: 2_200_000),
            ]),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
