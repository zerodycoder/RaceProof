<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Integration;

use RaceProof\Laravel\Data\ParticipantResult;
use RaceProof\Laravel\Data\TimelineEvent;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Results\RaceResult;
use RaceProof\Laravel\Results\RaceTimeline;
use RaceProof\Laravel\Studio\ReportArchive;

final class StudioArchiveTest extends TestCase
{
    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = dirname(__DIR__, 2).'/build/studio-archive-tests/'.bin2hex(random_bytes(8));
        $this->app['config']->set('raceproof.studio.enabled', true);
        $this->app['config']->set('raceproof.studio.path', $this->archivePath);
        $this->app->forgetInstance(ReportArchive::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->archivePath);

        parent::tearDown();
    }

    public function test_it_persists_only_bounded_redacted_reports_without_artifact_paths(): void
    {
        $runId = str_repeat('a', 32);
        $archive = $this->app->make(ReportArchive::class);

        $archive->store($this->raceResult($runId));

        $path = $this->archivePath.'/'.$runId.'.json';
        $contents = file_get_contents($path);
        $run = $archive->find($runId);

        self::assertIsString($contents);
        self::assertNotNull($run);
        self::assertSame('failed', $run->outcome);
        self::assertSame(2, $run->timelineEventCount);
        self::assertCount(2, $run->events);
        self::assertStringContainsString('[REDACTED]', $contents);
        self::assertStringNotContainsString('body-secret', $contents);
        self::assertStringNotContainsString('timeline-secret', $contents);
        self::assertStringNotContainsString('/private/artifacts', $contents);
        self::assertNull(
            json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['report']['run']['artifact_path'],
        );
    }

    public function test_it_skips_malformed_or_oversized_archives_and_rejects_oversized_writes(): void
    {
        $archive = $this->app->make(ReportArchive::class);
        mkdir($this->archivePath, 0700, true);
        file_put_contents($this->archivePath.'/'.str_repeat('b', 32).'.json', '{broken');
        file_put_contents($this->archivePath.'/'.str_repeat('c', 32).'.json', str_repeat('x', 2_048));
        $this->app['config']->set('raceproof.studio.max_report_bytes', 1_024);

        self::assertSame([], $archive->all());
        self::assertNull($archive->find(str_repeat('b', 32)));

        $this->app['config']->set('raceproof.studio.max_report_bytes', 10);

        $this->expectException(RaceProofException::class);
        $this->expectExceptionMessage('exceeds');

        $archive->store($this->raceResult(str_repeat('d', 32)));
    }

    public function test_it_prunes_the_oldest_reports_to_the_configured_retention_limit(): void
    {
        $this->app['config']->set('raceproof.studio.max_reports', 2);
        $archive = $this->app->make(ReportArchive::class);
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $third = str_repeat('c', 32);
        mkdir($this->archivePath, 0700, true);
        file_put_contents($this->archivePath.'/settings.json', '{"keep":true}');

        $archive->store($this->raceResult($first));
        touch($this->archivePath.'/'.$first.'.json', time() - 20);
        $archive->store($this->raceResult($second));
        touch($this->archivePath.'/'.$second.'.json', time() - 10);
        $archive->store($this->raceResult($third));

        self::assertFileDoesNotExist($this->archivePath.'/'.$first.'.json');
        self::assertFileExists($this->archivePath.'/'.$second.'.json');
        self::assertFileExists($this->archivePath.'/'.$third.'.json');
        self::assertFileExists($this->archivePath.'/settings.json');
        self::assertCount(2, $archive->all());
    }

    public function test_it_never_archives_or_reads_reports_in_production(): void
    {
        $this->app['config']->set('app.env', 'production');
        $archive = $this->app->make(ReportArchive::class);

        $archive->store($this->raceResult(str_repeat('e', 32)));

        self::assertFalse($archive->available());
        self::assertSame([], $archive->all());
        self::assertDirectoryDoesNotExist($this->archivePath);
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
                    body: '{"password":"body-secret"}',
                    headers: ['authorization' => 'Bearer header-secret'],
                ),
                new ParticipantResult(
                    runId: $runId,
                    participantId: 'p2',
                    status: 409,
                    startedAtNs: 1_100_000,
                    finishedAtNs: 2_200_000,
                    body: '{"conflict":true}',
                ),
            ],
            artifactPath: '/private/artifacts/'.$runId,
            timeline: new RaceTimeline($runId, [
                TimelineEvent::make(
                    $runId,
                    'participant.ready',
                    'p1',
                    data: ['token' => 'timeline-secret'],
                    occurredAtNs: 900_000,
                ),
                TimelineEvent::make(
                    $runId,
                    'checkpoint.released',
                    checkpoint: 'stock-read',
                    occurredAtNs: 1_500_000,
                ),
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
