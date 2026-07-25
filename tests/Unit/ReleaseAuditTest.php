<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\AuditTools\ReleaseAudit;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/tools/audit/ReleaseAudit.php';

final class ReleaseAuditTest extends TestCase
{
    public function test_committed_audit_is_valid_current_and_honestly_blocked(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = ReleaseAudit::load($root.'/audit/release-audit.json');

        self::assertSame([], ReleaseAudit::validationErrors($root, $audit));
        self::assertSame(
            file_get_contents($root.'/docs/release-audit.md'),
            ReleaseAudit::render($audit),
        );

        $evidence = ReleaseAudit::machineEvidence($audit);

        self::assertSame(7, $evidence['automated_controls']);
        self::assertSame(6, $evidence['mutation_risk_hotspots']);
        self::assertSame('automated', $evidence['fresh_install']);
        self::assertSame('blocked-no-published-baseline', $evidence['published_upgrade']);
        self::assertSame('blocked', $evidence['release_status']);
        self::assertSame([18, 19, 20], $evidence['blocked_issues']);
        self::assertSame(
            [
                'The upgrade path from the previous published release is not verified.',
                'External gate public-package-publication in issue #18 is not verified.',
                'External gate beta-adoption-evidence in issue #19 is not verified.',
            ],
            ReleaseAudit::stableGateErrors($root, $audit),
        );
    }

    public function test_audit_rejects_a_missing_hotspot_source(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = ReleaseAudit::load($root.'/audit/release-audit.json');
        /** @var array<string, mixed> $control */
        $control = $audit['controls'][0];
        $control['sources'] = ['../outside-repository.php'];
        $audit['controls'][0] = $control;

        self::assertContains(
            '$.controls[0].sources[0] must resolve to a repository file.',
            ReleaseAudit::validationErrors($root, $audit),
        );
    }

    public function test_audit_cannot_mark_external_release_gates_complete_without_evidence(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = ReleaseAudit::load($root.'/audit/release-audit.json');
        /** @var array<string, mixed> $gate */
        $gate = $audit['external_gates'][0];
        $gate['status'] = 'complete';
        $audit['external_gates'][0] = $gate;

        self::assertContains(
            '$.external_gates[0].status must be blocked or verified.',
            ReleaseAudit::validationErrors($root, $audit),
        );
    }

    public function test_report_does_not_turn_pre_release_checks_into_stable_approval(): void
    {
        $root = dirname(__DIR__, 2);
        $report = ReleaseAudit::render(ReleaseAudit::load($root.'/audit/release-audit.json'));

        self::assertStringContainsString('not stable-release approval', $report);
        self::assertStringContainsString('No prior tagged or Packagist release exists', $report);
        self::assertStringContainsString('issues/18', $report);
        self::assertStringContainsString('issues/19', $report);
        self::assertStringContainsString('issues/20', $report);
        self::assertStringContainsString('do not claim a repository-wide mutation score', $report);
    }

    public function test_stable_gate_fails_on_the_current_real_external_blockers(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/tools/audit/gate.php'], $root);

        self::assertSame(1, $process->run());
        self::assertStringContainsString('published release is not verified', $process->getErrorOutput());
        self::assertStringContainsString('issue #18 is not verified', $process->getErrorOutput());
        self::assertStringContainsString('issue #19 is not verified', $process->getErrorOutput());
        self::assertStringContainsString('0/10 projects', $process->getErrorOutput());
        self::assertStringContainsString('0/5 consented cases', $process->getErrorOutput());
    }
}
