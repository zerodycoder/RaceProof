<?php

declare(strict_types=1);

namespace RaceProof\BetaTools;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class BetaEvidence
{
    private const ADOPTION_TARGET = 5;

    private const INVITATION_TARGET = 10;

    /** @var list<string> */
    private const DATABASE_DRIVERS = ['mysql', 'pgsql', 'sqlite', 'other'];

    /** @var list<string> */
    private const FEEDBACK_CATEGORIES = [
        'api',
        'bug',
        'compatibility',
        'documentation',
        'dx',
        'reporting',
        'other',
    ];

    /** @var list<string> */
    private const FEEDBACK_DISPOSITIONS = ['received', 'no-change', 'actionable', 'resolved'];

    /** @var list<string> */
    private const OUTCOMES = ['broken-reproduced', 'fix-verified', 'regression-added'];

    /** @var list<string> */
    private const PLATFORMS = ['linux', 'wsl2', 'macos', 'windows', 'other'];

    /** @var list<string> */
    private const ARCHITECTURES = ['x86_64', 'arm64', 'other'];

    /** @var list<string> */
    private const SCENARIOS = [
        'overselling',
        'coupon-redemption',
        'wallet-debit',
        'quote-acceptance',
        'unique-constraint',
        'lock-misuse',
        'deadlock',
        'lock-timeout',
        'custom',
    ];

    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read beta evidence registry: {$path}");
        }

        try {
            $registry = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid beta evidence JSON in {$path}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! self::isObject($registry)) {
            throw new RuntimeException("Beta evidence registry must be a JSON object: {$path}");
        }

        return $registry;
    }

    /**
     * Validation proves the public registry's shape and privacy invariants. It
     * cannot prove that a project or consent record exists; maintainers must
     * audit those private source records before updating the public registry.
     *
     * @param  array<string, mixed>  $registry
     * @return list<string>
     */
    public static function validationErrors(array $registry): array
    {
        $errors = [];
        self::rejectForbiddenKeys($registry, '$', $errors);
        self::exactKeys(
            $registry,
            ['$schema', 'schema_version', 'updated_on', 'invitation_summary', 'adoption_cases', 'feedback'],
            '$',
            $errors,
        );

        if (($registry['$schema'] ?? null) !== './evidence.schema.json') {
            $errors[] = '$.$schema must be "./evidence.schema.json".';
        }

        if (($registry['schema_version'] ?? null) !== 1) {
            $errors[] = '$.schema_version must be 1.';
        }

        self::date($registry['updated_on'] ?? null, '$.updated_on', true, $errors);

        $invitationSummary = self::object(
            $registry['invitation_summary'] ?? null,
            '$.invitation_summary',
            $errors,
        );
        $invitedProjects = 0;

        if ($invitationSummary !== null) {
            self::exactKeys($invitationSummary, ['invited_projects', 'reviewed_on'], '$.invitation_summary', $errors);
            $invitedProjects = self::nonNegativeInteger(
                $invitationSummary['invited_projects'] ?? null,
                '$.invitation_summary.invited_projects',
                $errors,
            );
            $reviewedOn = $invitationSummary['reviewed_on'] ?? null;
            self::date($reviewedOn, '$.invitation_summary.reviewed_on', true, $errors);

            if ($invitedProjects > 0 && $reviewedOn === null) {
                $errors[] = '$.invitation_summary.reviewed_on is required when invitations are recorded.';
            }
        }

        $adoptionCases = self::list($registry['adoption_cases'] ?? null, '$.adoption_cases', $errors);

        if ($adoptionCases !== null) {
            self::adoptionCases($adoptionCases, $invitedProjects, $errors);
        }

        $feedback = self::list($registry['feedback'] ?? null, '$.feedback', $errors);

        if ($feedback !== null) {
            self::feedback($feedback, $errors);
        }

        if ($adoptionCases !== null && $feedback !== null) {
            self::relationships($adoptionCases, $feedback, $errors);
        }

        if (
            ($invitedProjects > 0 || ($adoptionCases !== null && $adoptionCases !== []) || ($feedback !== null && $feedback !== []))
            && ($registry['updated_on'] ?? null) === null
        ) {
            $errors[] = '$.updated_on is required when beta evidence is recorded.';
        }

        self::chronology($registry, $errors);

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return list<string>
     */
    public static function releaseGateErrors(array $registry): array
    {
        $errors = self::validationErrors($registry);

        if ($errors !== []) {
            return $errors;
        }

        $invitationSummary = self::objectField($registry, 'invitation_summary');
        $adoptionCases = self::listField($registry, 'adoption_cases');
        $feedback = self::listField($registry, 'feedback');
        $invitedProjects = self::integerField($invitationSummary, 'invited_projects');

        if ($invitedProjects < self::INVITATION_TARGET) {
            $errors[] = sprintf(
                'Private-beta invitation gate is not met: %d/%d projects.',
                $invitedProjects,
                self::INVITATION_TARGET,
            );
        }

        if (count($adoptionCases) < self::ADOPTION_TARGET) {
            $errors[] = sprintf(
                'Confirmed-adopter gate is not met: %d/%d consented cases.',
                count($adoptionCases),
                self::ADOPTION_TARGET,
            );
        }

        $resolvedFeedback = array_filter(
            $feedback,
            static fn (mixed $record): bool => self::isObject($record)
                && ($record['disposition'] ?? null) === 'resolved',
        );

        if ($resolvedFeedback === []) {
            $errors[] = 'No consented actionable feedback record is linked to a resulting pull request.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $registry */
    public static function render(array $registry): string
    {
        $validationErrors = self::validationErrors($registry);

        if ($validationErrors !== []) {
            throw new RuntimeException("Cannot render invalid beta evidence:\n- ".implode("\n- ", $validationErrors));
        }

        $invitationSummary = self::objectField($registry, 'invitation_summary');
        $adoptionCases = self::listField($registry, 'adoption_cases');
        $feedback = self::listField($registry, 'feedback');
        $invitedProjects = self::integerField($invitationSummary, 'invited_projects');
        $resolvedFeedback = array_filter(
            $feedback,
            static fn (mixed $record): bool => self::isObject($record)
                && ($record['disposition'] ?? null) === 'resolved',
        );
        $updatedOn = $registry['updated_on'];
        $lines = [
            '# Private-beta evidence',
            '',
            'This report is generated from [`beta/evidence.json`](../beta/evidence.json). The public registry accepts only bounded, anonymized records whose publication consent was recorded. Project identities, contacts, raw logs, environment dumps, and private feedback remain outside the repository.',
            '',
            'Last evidence review: '.(is_string($updatedOn) ? $updatedOn : 'not started'),
            '',
            '## Release gates',
            '',
            '| Gate | Evidence | Status |',
            '| --- | ---: | --- |',
            self::gateRow('Real projects invited', $invitedProjects, self::INVITATION_TARGET),
            self::gateRow('Confirmed adopters with tested scenarios', count($adoptionCases), self::ADOPTION_TARGET),
            self::gateRow('Actionable feedback linked to a merged fix', count($resolvedFeedback), 1),
            '',
            'These counts are release evidence only after a maintainer has audited the private source records. Passing schema validation is not proof of an invitation, adoption, test run, consent, or fix.',
            '',
            '## Consented environment and scenario coverage',
            '',
        ];

        if ($adoptionCases === []) {
            $lines[] = 'No consented adoption case has been published.';
        } else {
            $lines[] = '| Case | PHP | Laravel | Database | Platform | Scenarios | Outcomes |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- |';

            foreach ($adoptionCases as $case) {
                if (! self::isObject($case)) {
                    throw new RuntimeException('Validated adoption case unexpectedly changed shape.');
                }

                $environment = self::objectField($case, 'environment');
                $database = self::objectField($environment, 'database');
                $platform = self::objectField($environment, 'platform');
                $scenarios = self::stringListField($case, 'scenarios');
                $outcomes = self::listField($case, 'outcomes');
                $outcomeLabels = [];

                foreach ($outcomes as $outcome) {
                    if (self::isObject($outcome)) {
                        $outcomeLabels[] = self::stringField($outcome, 'kind')
                            .' x '.self::integerField($outcome, 'iterations');
                    }
                }

                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s %s | %s/%s | %s | %s |',
                    self::stringField($case, 'case_id'),
                    self::stringField($environment, 'php'),
                    self::stringField($environment, 'laravel'),
                    self::stringField($database, 'driver'),
                    self::stringField($database, 'version'),
                    self::stringField($platform, 'os'),
                    self::stringField($platform, 'architecture'),
                    implode(', ', $scenarios),
                    implode(', ', $outcomeLabels),
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Consented feedback outcomes';
        $lines[] = '';

        if ($feedback === []) {
            $lines[] = 'No consented feedback outcome has been published.';
        } else {
            $lines[] = '| Record | Category | Disposition | Issue | Resulting PR |';
            $lines[] = '| --- | --- | --- | ---: | ---: |';

            foreach ($feedback as $record) {
                if (! self::isObject($record)) {
                    throw new RuntimeException('Validated feedback record unexpectedly changed shape.');
                }

                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    self::stringField($record, 'feedback_id'),
                    self::stringField($record, 'category'),
                    self::stringField($record, 'disposition'),
                    self::reference($record['issue_number'] ?? null, 'issues'),
                    self::reference($record['resolved_by_pr'] ?? null, 'pull'),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'See [the private-beta runbook](private-beta.md) for definitions, consent withdrawal, safe collection, and the audit procedure.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<mixed>  $cases
     * @param  list<string>  $errors
     */
    private static function adoptionCases(array $cases, int $invitedProjects, array &$errors): void
    {
        $identifiers = [];

        foreach ($cases as $index => $case) {
            $path = "$.adoption_cases[{$index}]";
            $case = self::object($case, $path, $errors);

            if ($case === null) {
                continue;
            }

            self::exactKeys(
                $case,
                ['case_id', 'confirmed_month', 'environment', 'scenarios', 'outcomes', 'feedback_issues', 'consent'],
                $path,
                $errors,
            );
            $identifier = $case['case_id'] ?? null;

            if (! is_string($identifier) || preg_match('/^beta-[0-9]{3}$/D', $identifier) !== 1) {
                $errors[] = "{$path}.case_id must match beta-NNN.";
            } elseif (isset($identifiers[$identifier])) {
                $errors[] = "{$path}.case_id must be unique.";
            } else {
                $identifiers[$identifier] = true;
            }

            self::month($case['confirmed_month'] ?? null, "{$path}.confirmed_month", $errors);
            self::environment($case['environment'] ?? null, "{$path}.environment", $errors);
            self::enumList($case['scenarios'] ?? null, self::SCENARIOS, "{$path}.scenarios", $errors);
            self::outcomes($case['outcomes'] ?? null, "{$path}.outcomes", $errors);
            self::positiveIntegerList(
                $case['feedback_issues'] ?? null,
                "{$path}.feedback_issues",
                $errors,
            );
            self::consent($case['consent'] ?? null, "{$path}.consent", $errors);
        }

        if (count($cases) > $invitedProjects) {
            $errors[] = '$.adoption_cases cannot contain more cases than the audited invitation count.';
        }
    }

    /** @param list<string> $errors */
    private static function environment(mixed $value, string $path, array &$errors): void
    {
        $environment = self::object($value, $path, $errors);

        if ($environment === null) {
            return;
        }

        self::exactKeys($environment, ['php', 'laravel', 'database', 'platform'], $path, $errors);
        self::pattern(
            $environment['php'] ?? null,
            '/^8\.(?:[2-9])(?:\.\d+)?$/D',
            "{$path}.php",
            'a supported PHP 8.2+ version',
            $errors,
        );
        self::pattern(
            $environment['laravel'] ?? null,
            '/^(?:12|13)(?:\.\d+){0,2}$/D',
            "{$path}.laravel",
            'a supported Laravel 12 or 13 version',
            $errors,
        );

        $database = self::object($environment['database'] ?? null, "{$path}.database", $errors);

        if ($database !== null) {
            self::exactKeys($database, ['driver', 'version'], "{$path}.database", $errors);
            self::enum($database['driver'] ?? null, self::DATABASE_DRIVERS, "{$path}.database.driver", $errors);
            self::pattern(
                $database['version'] ?? null,
                '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,31}$/D',
                "{$path}.database.version",
                'a bounded version token',
                $errors,
            );
        }

        $platform = self::object($environment['platform'] ?? null, "{$path}.platform", $errors);

        if ($platform !== null) {
            self::exactKeys($platform, ['os', 'architecture'], "{$path}.platform", $errors);
            self::enum($platform['os'] ?? null, self::PLATFORMS, "{$path}.platform.os", $errors);
            self::enum(
                $platform['architecture'] ?? null,
                self::ARCHITECTURES,
                "{$path}.platform.architecture",
                $errors,
            );
        }
    }

    /** @param list<string> $errors */
    private static function outcomes(mixed $value, string $path, array &$errors): void
    {
        $outcomes = self::list($value, $path, $errors);

        if ($outcomes === null) {
            return;
        }

        if ($outcomes === []) {
            $errors[] = "{$path} must contain at least one tested outcome.";
        }

        $kinds = [];

        foreach ($outcomes as $index => $outcome) {
            $outcomePath = "{$path}[{$index}]";
            $outcome = self::object($outcome, $outcomePath, $errors);

            if ($outcome === null) {
                continue;
            }

            self::exactKeys($outcome, ['kind', 'iterations'], $outcomePath, $errors);
            $kind = $outcome['kind'] ?? null;
            self::enum($kind, self::OUTCOMES, "{$outcomePath}.kind", $errors);

            if (is_string($kind) && isset($kinds[$kind])) {
                $errors[] = "{$outcomePath}.kind must be unique within a case.";
            } elseif (is_string($kind)) {
                $kinds[$kind] = true;
            }

            self::positiveInteger($outcome['iterations'] ?? null, "{$outcomePath}.iterations", $errors);
        }
    }

    /**
     * @param  list<mixed>  $records
     * @param  list<string>  $errors
     */
    private static function feedback(array $records, array &$errors): void
    {
        $identifiers = [];

        foreach ($records as $index => $record) {
            $path = "$.feedback[{$index}]";
            $record = self::object($record, $path, $errors);

            if ($record === null) {
                continue;
            }

            self::exactKeys(
                $record,
                [
                    'feedback_id',
                    'received_month',
                    'category',
                    'disposition',
                    'issue_number',
                    'resolved_by_pr',
                    'consent',
                ],
                $path,
                $errors,
            );
            $identifier = $record['feedback_id'] ?? null;

            if (! is_string($identifier) || preg_match('/^feedback-[0-9]{3}$/D', $identifier) !== 1) {
                $errors[] = "{$path}.feedback_id must match feedback-NNN.";
            } elseif (isset($identifiers[$identifier])) {
                $errors[] = "{$path}.feedback_id must be unique.";
            } else {
                $identifiers[$identifier] = true;
            }

            self::month($record['received_month'] ?? null, "{$path}.received_month", $errors);
            self::enum($record['category'] ?? null, self::FEEDBACK_CATEGORIES, "{$path}.category", $errors);
            $disposition = $record['disposition'] ?? null;
            self::enum($disposition, self::FEEDBACK_DISPOSITIONS, "{$path}.disposition", $errors);
            $issueNumber = self::nullablePositiveInteger(
                $record['issue_number'] ?? null,
                "{$path}.issue_number",
                $errors,
            );
            $pullRequest = self::nullablePositiveInteger(
                $record['resolved_by_pr'] ?? null,
                "{$path}.resolved_by_pr",
                $errors,
            );

            if (in_array($disposition, ['actionable', 'resolved'], true) && $issueNumber === null) {
                $errors[] = "{$path}.issue_number is required for actionable or resolved feedback.";
            }

            if ($disposition === 'resolved' && $pullRequest === null) {
                $errors[] = "{$path}.resolved_by_pr is required for resolved feedback.";
            }

            if ($disposition !== 'resolved' && $pullRequest !== null) {
                $errors[] = "{$path}.resolved_by_pr is only allowed for resolved feedback.";
            }

            self::consent($record['consent'] ?? null, "{$path}.consent", $errors);
        }
    }

    /**
     * @param  list<mixed>  $cases
     * @param  list<mixed>  $feedback
     * @param  list<string>  $errors
     */
    private static function relationships(array $cases, array $feedback, array &$errors): void
    {
        $feedbackIssues = [];

        foreach ($feedback as $record) {
            if (self::isObject($record) && is_int($record['issue_number'] ?? null)) {
                $feedbackIssues[$record['issue_number']] = true;
            }
        }

        foreach ($cases as $index => $case) {
            if (! self::isObject($case) || ! is_array($case['feedback_issues'] ?? null)) {
                continue;
            }

            foreach ($case['feedback_issues'] as $issueIndex => $issueNumber) {
                if (is_int($issueNumber) && ! isset($feedbackIssues[$issueNumber])) {
                    $errors[] = sprintf(
                        '$.adoption_cases[%d].feedback_issues[%d] does not reference a public feedback record.',
                        $index,
                        $issueIndex,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  list<string>  $errors
     */
    private static function chronology(array $registry, array &$errors): void
    {
        $updatedOn = $registry['updated_on'] ?? null;
        $invitationSummary = $registry['invitation_summary'] ?? null;

        if (
            is_string($updatedOn)
            && self::isObject($invitationSummary)
            && is_string($invitationSummary['reviewed_on'] ?? null)
            && $invitationSummary['reviewed_on'] > $updatedOn
        ) {
            $errors[] = '$.updated_on cannot predate $.invitation_summary.reviewed_on.';
        }

        foreach (['adoption_cases' => 'confirmed_month', 'feedback' => 'received_month'] as $listKey => $monthKey) {
            $records = $registry[$listKey] ?? null;

            if (! is_array($records) || ! array_is_list($records)) {
                continue;
            }

            foreach ($records as $index => $record) {
                if (! self::isObject($record) || ! self::isObject($record['consent'] ?? null)) {
                    continue;
                }

                $month = $record[$monthKey] ?? null;
                $recordedOn = $record['consent']['recorded_on'] ?? null;
                $path = "$.{$listKey}[{$index}].consent.recorded_on";

                if (is_string($month) && is_string($recordedOn) && substr($recordedOn, 0, 7) < $month) {
                    $errors[] = "{$path} cannot predate the evidence month.";
                }

                if (is_string($updatedOn) && is_string($recordedOn) && $recordedOn > $updatedOn) {
                    $errors[] = "$.updated_on cannot predate {$path}.";
                }
            }
        }
    }

    /** @param list<string> $errors */
    private static function consent(mixed $value, string $path, array &$errors): void
    {
        $consent = self::object($value, $path, $errors);

        if ($consent === null) {
            return;
        }

        self::exactKeys($consent, ['anonymized_publication', 'recorded_on'], $path, $errors);

        if (($consent['anonymized_publication'] ?? null) !== true) {
            $errors[] = "{$path}.anonymized_publication must be true for a public record.";
        }

        self::date($consent['recorded_on'] ?? null, "{$path}.recorded_on", false, $errors);
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private static function enumList(mixed $value, array $allowed, string $path, array &$errors): void
    {
        $values = self::list($value, $path, $errors);

        if ($values === null) {
            return;
        }

        if ($values === []) {
            $errors[] = "{$path} must contain at least one value.";
        }

        $seen = [];

        foreach ($values as $index => $item) {
            self::enum($item, $allowed, "{$path}[{$index}]", $errors);

            if (is_string($item) && isset($seen[$item])) {
                $errors[] = "{$path}[{$index}] must be unique.";
            } elseif (is_string($item)) {
                $seen[$item] = true;
            }
        }
    }

    /** @param list<string> $errors */
    private static function positiveIntegerList(mixed $value, string $path, array &$errors): void
    {
        $values = self::list($value, $path, $errors);

        if ($values === null) {
            return;
        }

        $seen = [];

        foreach ($values as $index => $item) {
            $number = self::positiveInteger($item, "{$path}[{$index}]", $errors);

            if ($number > 0 && isset($seen[$number])) {
                $errors[] = "{$path}[{$index}] must be unique.";
            } elseif ($number > 0) {
                $seen[$number] = true;
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private static function enum(mixed $value, array $allowed, string $path, array &$errors): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $errors[] = "{$path} must be one of: ".implode(', ', $allowed).'.';
        }
    }

    /** @param list<string> $errors */
    private static function pattern(
        mixed $value,
        string $pattern,
        string $path,
        string $description,
        array &$errors,
    ): void {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $errors[] = "{$path} must be {$description}.";
        }
    }

    /** @param list<string> $errors */
    private static function month(mixed $value, string $path, array &$errors): void
    {
        if (! is_string($value) || preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $value) !== 1) {
            $errors[] = "{$path} must be a YYYY-MM month.";
        }
    }

    /** @param list<string> $errors */
    private static function date(mixed $value, string $path, bool $nullable, array &$errors): void
    {
        if ($nullable && $value === null) {
            return;
        }

        if (! is_string($value)) {
            $errors[] = "{$path} must be ".($nullable ? 'null or ' : '').'a YYYY-MM-DD date.';

            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = "{$path} must be ".($nullable ? 'null or ' : '').'a valid YYYY-MM-DD date.';
        }
    }

    /** @param list<string> $errors */
    private static function nonNegativeInteger(mixed $value, string $path, array &$errors): int
    {
        if (! is_int($value) || $value < 0) {
            $errors[] = "{$path} must be a non-negative integer.";

            return 0;
        }

        return $value;
    }

    /** @param list<string> $errors */
    private static function positiveInteger(mixed $value, string $path, array &$errors): int
    {
        if (! is_int($value) || $value < 1) {
            $errors[] = "{$path} must be a positive integer.";

            return 0;
        }

        return $value;
    }

    /** @param list<string> $errors */
    private static function nullablePositiveInteger(mixed $value, string $path, array &$errors): ?int
    {
        if ($value === null) {
            return null;
        }

        $number = self::positiveInteger($value, $path, $errors);

        return $number > 0 ? $number : null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private static function exactKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        $actual = array_keys($value);
        $missing = array_diff($allowed, $actual);
        $unexpected = array_diff($actual, $allowed);

        foreach ($missing as $key) {
            $errors[] = "{$path}.{$key} is required.";
        }

        foreach ($unexpected as $key) {
            $errors[] = "{$path}.{$key} is not allowed.";
        }
    }

    /** @param list<string> $errors */
    private static function rejectForbiddenKeys(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = "{$path}.{$key}";

            if (
                is_string($key)
                && preg_match(
                    '/(?:^|_)(?:authorization|company|contact|cookie|dsn|email|name|password|repository|secret|token|url)(?:$|_)/i',
                    $key,
                ) === 1
            ) {
                $errors[] = "{$childPath} is forbidden in public beta evidence.";
            }

            self::rejectForbiddenKeys($child, $childPath, $errors);
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value, string $path, array &$errors): ?array
    {
        if (! self::isObject($value)) {
            $errors[] = "{$path} must be an object.";

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $errors
     * @return list<mixed>|null
     */
    private static function list(mixed $value, string $path, array &$errors): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = "{$path} must be a list.";

            return null;
        }

        return $value;
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private static function objectField(array $object, string $field): array
    {
        $value = $object[$field] ?? null;

        if (! self::isObject($value)) {
            throw new RuntimeException("Validated beta evidence field {$field} is not an object.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return list<mixed>
     */
    private static function listField(array $object, string $field): array
    {
        $value = $object[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Validated beta evidence field {$field} is not a list.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return list<string>
     */
    private static function stringListField(array $object, string $field): array
    {
        $values = self::listField($object, $field);
        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException("Validated beta evidence field {$field} contains a non-string value.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /** @param array<string, mixed> $object */
    private static function stringField(array $object, string $field): string
    {
        $value = $object[$field] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Validated beta evidence field {$field} is not a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    private static function integerField(array $object, string $field): int
    {
        $value = $object[$field] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("Validated beta evidence field {$field} is not an integer.");
        }

        return $value;
    }

    private static function gateRow(string $name, int $actual, int $target): string
    {
        return sprintf(
            '| %s | %d/%d | %s |',
            $name,
            $actual,
            $target,
            $actual >= $target ? 'Met' : 'Not met',
        );
    }

    private static function reference(mixed $number, string $type): string
    {
        if (! is_int($number) || $number < 1) {
            return '—';
        }

        return sprintf('[#%d](https://github.com/zerodycoder/RaceProof/%s/%d)', $number, $type, $number);
    }
}
