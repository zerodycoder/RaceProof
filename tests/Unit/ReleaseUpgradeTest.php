<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\ReleaseTools\UpgradeRehearsal;
use RuntimeException;

require_once dirname(__DIR__, 2).'/tools/release/Release.php';
require_once dirname(__DIR__, 2).'/tools/release/UpgradeRehearsal.php';

final class ReleaseUpgradeTest extends TestCase
{
    public function test_baseline_manifest_uses_only_exact_published_packages(): void
    {
        $manifest = UpgradeRehearsal::baselineManifest($this->manifest(), '1.0.0-beta.1');

        self::assertArrayNotHasKey('repositories', $manifest);
        self::assertSame('1.0.0-beta.1', $manifest['require']['raceproof/runtime'] ?? null);
        self::assertSame('1.0.0-beta.1', $manifest['require-dev']['raceproof/laravel'] ?? null);
        self::assertSame('beta', $manifest['minimum-stability'] ?? null);
        self::assertTrue($manifest['prefer-stable'] ?? false);
    }

    public function test_candidate_manifest_uses_both_exact_candidate_artifacts(): void
    {
        $manifest = UpgradeRehearsal::candidateManifest(
            UpgradeRehearsal::baselineManifest($this->manifest(), '1.0.0-beta.1'),
            '1.0.0-rc.1',
            'C:\\release\\artifacts',
        );

        self::assertSame([[
            'type' => 'artifact',
            'url' => 'C:/release/artifacts',
            'canonical' => true,
        ]], $manifest['repositories'] ?? null);
        self::assertSame('1.0.0-rc.1', $manifest['require']['raceproof/runtime'] ?? null);
        self::assertSame('1.0.0-rc.1', $manifest['require-dev']['raceproof/laravel'] ?? null);
        self::assertSame('RC', $manifest['minimum-stability'] ?? null);

        $candidate = UpgradeRehearsal::packageEvidence(
            $this->lock('1.0.0-rc.1', ''),
            '1.0.0-rc.1',
            false,
        );

        self::assertSame('zip', $candidate['raceproof/runtime']['dist_type']);
    }

    public function test_published_lock_evidence_requires_immutable_references(): void
    {
        $reference = str_repeat('a', 40);
        $lock = $this->lock('1.0.0-beta.1', $reference);

        $evidence = UpgradeRehearsal::packageEvidence($lock, '1.0.0-beta.1', true);

        self::assertSame($reference, $evidence['raceproof/laravel']['source_reference']);
        self::assertSame($reference, $evidence['raceproof/runtime']['dist_reference']);
        self::assertSame('zip', $evidence['raceproof/laravel']['dist_type']);

        $lock['packages'][0]['dist']['reference'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable source and dist references');

        UpgradeRehearsal::packageEvidence($lock, '1.0.0-beta.1', true);
    }

    public function test_candidate_must_be_newer_and_use_a_distinct_source_commit(): void
    {
        UpgradeRehearsal::assertVersionProgression('1.0.0-beta.1', '1.0.0-rc.1');
        UpgradeRehearsal::assertCleanSource("\n");
        $baseline = UpgradeRehearsal::packageEvidence(
            $this->lock('1.0.0-beta.1', str_repeat('a', 40)),
            '1.0.0-beta.1',
            true,
        );
        UpgradeRehearsal::assertDistinctCandidateSource(str_repeat('b', 40), $baseline);
        $this->addToAssertionCount(3);

        try {
            UpgradeRehearsal::assertVersionProgression('1.0.0-beta.1', '1.0.0-beta.1');
            self::fail('Expected a non-increasing candidate version to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be newer', $exception->getMessage());
        }

        try {
            UpgradeRehearsal::assertCleanSource(' M composer.json');
            self::fail('Expected a dirty candidate source to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('clean source checkout', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must differ from the published Laravel baseline');

        UpgradeRehearsal::assertDistinctCandidateSource(str_repeat('a', 40), $baseline);
    }

    public function test_release_dry_run_includes_rehearsal_without_claiming_final_verification(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = UpgradeRehearsal::loadJson($root.'/composer.json');
        $audit = UpgradeRehearsal::loadJson($root.'/audit/release-audit.json');

        self::assertSame(
            [
                '@php tools/release/dry-run.php',
                '@release:upgrade-dry-run',
            ],
            $composer['scripts']['release:dry-run'] ?? null,
        );
        self::assertSame(
            '@php tools/release/upgrade-dry-run.php',
            $composer['scripts']['release:upgrade-dry-run'] ?? null,
        );
        self::assertSame(
            'pending-from-published-beta',
            $audit['artifacts']['upgrade_from_published_release'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'name' => 'raceproof/upgrade-fixture',
            'repositories' => [['type' => 'path', 'url' => '../../']],
            'require' => [
                'php' => '^8.2',
                'raceproof/runtime' => '^1.0@beta',
            ],
            'require-dev' => [
                'raceproof/laravel' => '^1.0@beta',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function lock(string $version, string $reference): array
    {
        return [
            'packages' => [[
                'name' => 'raceproof/runtime',
                'version' => $version,
                'source' => ['reference' => $reference],
                'dist' => ['type' => 'zip', 'reference' => $reference],
            ]],
            'packages-dev' => [[
                'name' => 'raceproof/laravel',
                'version' => $version,
                'source' => ['reference' => $reference],
                'dist' => ['type' => 'zip', 'reference' => $reference],
            ]],
        ];
    }
}
