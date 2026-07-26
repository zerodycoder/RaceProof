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

        self::assertSame(8, $evidence['automated_controls']);
        self::assertSame(6, $evidence['mutation_risk_hotspots']);
        self::assertSame('automated', $evidence['fresh_install']);
        self::assertSame('blocked-no-published-baseline', $evidence['published_upgrade']);
        self::assertSame('blocked', $evidence['release_status']);
        self::assertSame([2, 3, 4], $evidence['blocked_issues']);
        self::assertSame(
            [
                'The upgrade path from the previous published release is not verified.',
                'External gate public-package-publication in issue #2 is not verified.',
                'External gate beta-adoption-evidence in issue #3 is not verified.',
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

    public function test_external_gate_issue_numbers_can_move_without_weakening_the_gate_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = ReleaseAudit::load($root.'/audit/release-audit.json');

        foreach ([2, 3, 4] as $index => $issue) {
            /** @var array<string, mixed> $gate */
            $gate = $audit['external_gates'][$index];
            $gate['issue'] = $issue;
            $audit['external_gates'][$index] = $gate;
        }

        self::assertSame([], ReleaseAudit::validationErrors($root, $audit));
        self::assertStringContainsString(
            'package-publication gate in #2 and beta-adoption gate in #3',
            ReleaseAudit::render($audit),
        );
        self::assertSame([2, 3, 4], ReleaseAudit::machineEvidence($audit)['blocked_issues']);
    }

    public function test_external_gate_issue_numbers_must_be_positive_and_unique(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = ReleaseAudit::load($root.'/audit/release-audit.json');
        /** @var array<string, mixed> $first */
        $first = $audit['external_gates'][0];
        /** @var array<string, mixed> $second */
        $second = $audit['external_gates'][1];
        /** @var array<string, mixed> $third */
        $third = $audit['external_gates'][2];
        $first['issue'] = 0;
        $second['issue'] = $third['issue'];
        $audit['external_gates'][0] = $first;
        $audit['external_gates'][1] = $second;

        $errors = ReleaseAudit::validationErrors($root, $audit);

        self::assertContains(
            '$.external_gates[0] must contain a string id and positive integer issue.',
            $errors,
        );
        self::assertContains('$.external_gates issue numbers must be unique.', $errors);
    }

    public function test_report_does_not_turn_pre_release_checks_into_stable_approval(): void
    {
        $root = dirname(__DIR__, 2);
        $report = ReleaseAudit::render(ReleaseAudit::load($root.'/audit/release-audit.json'));

        self::assertStringContainsString('not stable-release approval', $report);
        self::assertStringContainsString('No prior tagged or Packagist release exists', $report);
        self::assertStringContainsString('issues/2', $report);
        self::assertStringContainsString('issues/3', $report);
        self::assertStringContainsString('issues/4', $report);
        self::assertStringContainsString('timeouts remain in its denominator', $report);
        self::assertStringContainsString('do not claim a repository-wide mutation score', $report);
    }

    public function test_stable_gate_fails_on_the_current_real_external_blockers(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/tools/audit/gate.php'], $root);

        self::assertSame(1, $process->run());
        self::assertStringContainsString('published release is not verified', $process->getErrorOutput());
        self::assertStringContainsString('issue #2 is not verified', $process->getErrorOutput());
        self::assertStringContainsString('issue #3 is not verified', $process->getErrorOutput());
        self::assertStringContainsString('0/10 projects', $process->getErrorOutput());
        self::assertStringContainsString('0/5 consented cases', $process->getErrorOutput());
    }
}
