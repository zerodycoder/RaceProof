<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RaceProof\BetaTools\BetaEvidence;

require_once dirname(__DIR__, 2).'/tools/beta/BetaEvidence.php';

final class BetaEvidenceTest extends TestCase
{
    public function test_public_registry_schema_is_valid_and_closed_to_unknown_fields(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/beta/evidence.schema.json');

        self::assertIsString($contents);
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? true);
        self::assertSame(true, $schema['$defs']['consent']['properties']['anonymized_publication']['const'] ?? null);
        self::assertFalse($schema['$defs']['adoptionCase']['additionalProperties'] ?? true);
        self::assertFalse($schema['$defs']['feedback']['additionalProperties'] ?? true);
    }

    public function test_committed_registry_is_valid_current_and_explicitly_incomplete(): void
    {
        $root = dirname(__DIR__, 2);
        $registry = BetaEvidence::load($root.'/beta/evidence.json');

        self::assertSame([], BetaEvidence::validationErrors($registry));
        self::assertSame(
            file_get_contents($root.'/docs/beta-evidence.md'),
            BetaEvidence::render($registry),
        );
        self::assertSame(
            [
                'Private-beta invitation gate is not met: 0/10 projects.',
                'Confirmed-adopter gate is not met: 0/5 consented cases.',
                'No consented actionable feedback record is linked to a resulting pull request.',
            ],
            BetaEvidence::releaseGateErrors($registry),
        );
        self::assertStringContainsString('| Real projects invited | 0/10 | Not met |', BetaEvidence::render($registry));
        self::assertStringContainsString('No consented adoption case has been published.', BetaEvidence::render($registry));
    }

    public function test_release_gate_accepts_only_a_complete_consented_registry(): void
    {
        $registry = $this->completeRegistry();

        self::assertSame([], BetaEvidence::validationErrors($registry));
        self::assertSame([], BetaEvidence::releaseGateErrors($registry));

        $report = BetaEvidence::render($registry);

        self::assertStringContainsString('| Real projects invited | 10/10 | Met |', $report);
        self::assertStringContainsString('| Confirmed adopters with tested scenarios | 5/5 | Met |', $report);
        self::assertStringContainsString('[#3](https://github.com/zerodycoder/RaceProof/issues/3)', $report);
        self::assertStringContainsString('[#101](https://github.com/zerodycoder/RaceProof/pull/101)', $report);
    }

    public function test_public_records_reject_identity_secret_and_unconsented_fields(): void
    {
        $registry = $this->completeRegistry();
        /** @var array<string, mixed> $case */
        $case = $registry['adoption_cases'][0];
        /** @var array<string, mixed> $consent */
        $consent = $case['consent'];
        $consent['anonymized_publication'] = false;
        $case['consent'] = $consent;
        $case['email'] = 'must-not-enter-public-evidence@example.test';
        $registry['adoption_cases'][0] = $case;

        $errors = BetaEvidence::validationErrors($registry);

        self::assertContains('$.adoption_cases.0.email is forbidden in public beta evidence.', $errors);
        self::assertContains('$.adoption_cases[0].email is not allowed.', $errors);
        self::assertContains(
            '$.adoption_cases[0].consent.anonymized_publication must be true for a public record.',
            $errors,
        );
    }

    public function test_feedback_cannot_claim_a_fix_without_issue_and_pull_request_evidence(): void
    {
        $registry = $this->completeRegistry();
        /** @var array<string, mixed> $feedback */
        $feedback = $registry['feedback'][0];
        $feedback['issue_number'] = null;
        $feedback['resolved_by_pr'] = null;
        $registry['feedback'][0] = $feedback;

        $errors = BetaEvidence::validationErrors($registry);

        self::assertContains('$.feedback[0].issue_number is required for actionable or resolved feedback.', $errors);
        self::assertContains('$.feedback[0].resolved_by_pr is required for resolved feedback.', $errors);
        self::assertContains(
            '$.adoption_cases[0].feedback_issues[0] does not reference a public feedback record.',
            $errors,
        );
    }

    public function test_consent_and_registry_review_cannot_predate_their_evidence(): void
    {
        $registry = $this->completeRegistry();
        $registry['updated_on'] = '2026-06-30';
        /** @var array<string, mixed> $case */
        $case = $registry['adoption_cases'][0];
        /** @var array<string, mixed> $consent */
        $consent = $case['consent'];
        $consent['recorded_on'] = '2026-06-30';
        $case['consent'] = $consent;
        $registry['adoption_cases'][0] = $case;

        $errors = BetaEvidence::validationErrors($registry);

        self::assertContains('$.updated_on cannot predate $.invitation_summary.reviewed_on.', $errors);
        self::assertContains(
            '$.adoption_cases[0].consent.recorded_on cannot predate the evidence month.',
            $errors,
        );
    }

    public function test_private_beta_templates_separate_feedback_consent_and_security(): void
    {
        $root = dirname(__DIR__, 2);
        $invitation = file_get_contents($root.'/docs/templates/private-beta-invitation.md');
        $onboarding = file_get_contents($root.'/docs/templates/private-beta-onboarding.md');
        $feedback = file_get_contents($root.'/docs/templates/private-beta-feedback.md');
        $consent = file_get_contents($root.'/docs/templates/anonymized-evidence-consent.md');

        self::assertIsString($invitation);
        self::assertIsString($onboarding);
        self::assertIsString($feedback);
        self::assertIsString($consent);
        self::assertStringContainsString('disposable non-production database', $invitation);
        self::assertStringContainsString('private-beta-onboarding.md', $invitation);
        self::assertStringContainsString('Reply privately with "interested"', $invitation);
        self::assertStringNotContainsString('â', $invitation);
        self::assertStringContainsString(
            'composer require raceproof/runtime:^1.0.0-beta.1@beta',
            $onboarding,
        );
        self::assertStringContainsString(
            'composer require raceproof/laravel:^1.0.0-beta.1@beta --dev',
            $onboarding,
        );
        self::assertStringContainsString('RACEPROOF_ALLOWED_DATABASES=', $onboarding);
        self::assertStringContainsString('raceproof:doctor --self-test', $onboarding);
        self::assertStringContainsString('raceproof:clean', $onboarding);
        self::assertStringContainsString('Silence is not consent', $onboarding);
        self::assertStringContainsString('Do not send retained RaceProof directories', $onboarding);
        self::assertStringContainsString('Publication is a separate step', $feedback);
        self::assertStringContainsString('does not imply consent', $consent);
        self::assertStringContainsString('No response is treated as no consent', $consent);
        self::assertStringContainsString('security channel', $feedback);
    }

    public function test_security_policy_matches_the_published_prerelease_boundary(): void
    {
        $security = file_get_contents(dirname(__DIR__, 2).'/SECURITY.md');

        self::assertIsString($security);
        self::assertStringContainsString('Signed `v1.0.0-beta.1`', $security);
        self::assertStringContainsString('not maintained as a separate line', $security);
        self::assertStringContainsString('fixes target the latest commit on `main`', $security);
        self::assertStringContainsString('no response-time SLA', $security);
        self::assertStringNotContainsString('No tagged or Packagist release exists', $security);
    }

    /** @return array<string, mixed> */
    private function completeRegistry(): array
    {
        $cases = [];

        for ($index = 1; $index <= 5; $index++) {
            $cases[] = [
                'case_id' => sprintf('beta-%03d', $index),
                'confirmed_month' => '2026-07',
                'environment' => [
                    'php' => $index % 2 === 0 ? '8.5' : '8.2',
                    'laravel' => $index % 2 === 0 ? '13' : '12',
                    'database' => [
                        'driver' => $index % 2 === 0 ? 'pgsql' : 'mysql',
                        'version' => $index % 2 === 0 ? '17' : '8.4',
                    ],
                    'platform' => [
                        'os' => 'linux',
                        'architecture' => 'x86_64',
                    ],
                ],
                'scenarios' => [$index % 2 === 0 ? 'wallet-debit' : 'overselling'],
                'outcomes' => [
                    [
                        'kind' => 'regression-added',
                        'iterations' => 100,
                    ],
                ],
                'feedback_issues' => $index === 1 ? [3] : [],
                'consent' => [
                    'anonymized_publication' => true,
                    'recorded_on' => '2026-07-25',
                ],
            ];
        }

        return [
            '$schema' => './evidence.schema.json',
            'schema_version' => 1,
            'updated_on' => '2026-07-25',
            'invitation_summary' => [
                'invited_projects' => 10,
                'reviewed_on' => '2026-07-25',
            ],
            'adoption_cases' => $cases,
            'feedback' => [
                [
                    'feedback_id' => 'feedback-001',
                    'received_month' => '2026-07',
                    'category' => 'dx',
                    'disposition' => 'resolved',
                    'issue_number' => 3,
                    'resolved_by_pr' => 101,
                    'consent' => [
                        'anonymized_publication' => true,
                        'recorded_on' => '2026-07-25',
                    ],
                ],
            ],
        ];
    }
}
