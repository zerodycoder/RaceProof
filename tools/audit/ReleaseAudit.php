<?php

declare(strict_types=1);

namespace RaceProof\AuditTools;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class ReleaseAudit
{
    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read release audit definition: {$path}");
        }

        try {
            $audit = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid release audit JSON in {$path}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! self::isObject($audit)) {
            throw new RuntimeException("Release audit definition must be a JSON object: {$path}");
        }

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return list<string>
     */
    public static function validationErrors(string $root, array $audit): array
    {
        $errors = [];
        self::exactKeys(
            $audit,
            ['schema_version', 'prepared_on', 'controls', 'policies', 'matrix', 'artifacts', 'external_gates'],
            '$',
            $errors,
        );

        if (($audit['schema_version'] ?? null) !== 1) {
            $errors[] = '$.schema_version must be 1.';
        }

        self::date($audit['prepared_on'] ?? null, '$.prepared_on', $errors);
        self::controls($root, $audit['controls'] ?? null, $errors);
        self::policies($root, $audit['policies'] ?? null, $errors);
        self::matrix($root, $audit['matrix'] ?? null, $errors);
        self::artifacts($audit['artifacts'] ?? null, $errors);
        self::externalGates($audit['external_gates'] ?? null, $errors);
        self::workflowSupplyChain($root, $errors);

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $audit */
    public static function render(array $audit): string
    {
        $controls = self::listField($audit, 'controls');
        $policies = self::objectField($audit, 'policies');
        $matrix = self::objectField($audit, 'matrix');
        $artifacts = self::objectField($audit, 'artifacts');
        $gates = self::listField($audit, 'external_gates');
        $lines = [
            '# Pre-release audit status',
            '',
            'This is a reproducible pre-release audit, not stable-release approval. It inventories executable controls, supported evidence, policies, artifact checks, and unresolved external gates from [`audit/release-audit.json`](../audit/release-audit.json).',
            '',
            'Audit definition prepared: '.self::stringField($audit, 'prepared_on'),
            '',
            '## Automated controls and mutation-risk hotspots',
            '',
            '| Control | Mutation hotspot | Scope | Test methods |',
            '| --- | --- | --- | ---: |',
        ];

        foreach ($controls as $control) {
            if (! self::isObject($control)) {
                throw new RuntimeException('Validated release audit control unexpectedly changed shape.');
            }

            $tests = self::listField($control, 'tests');
            $hotspot = $control['mutation_hotspot'] ?? null;
            $lines[] = sprintf(
                '| `%s` | %s | %s | %d |',
                self::stringField($control, 'id'),
                $hotspot === true ? 'yes' : 'no',
                self::stringField($control, 'scope'),
                count($tests),
            );
        }

        $lines[] = '';
        $lines[] = 'These entries identify mutation-sensitive branch, timeout, cleanup, redaction, serialization, and packaging decisions and bind each one to named tests. CI additionally enforces an 80% strict covered-code mutation score for nineteen selected safety, redaction, worker-lifecycle, orchestration, coordinator-selection, file/Redis-coordination, authenticated-remote-transport, queue-lifecycle, and report-projection classes through `composer test:mutation`; timeouts remain in its denominator and are never accepted as tested mutants. This remains targeted evidence, so do not claim a repository-wide mutation score.';
        $lines[] = '';
        $lines[] = '## Compatibility evidence';
        $lines[] = '';
        $lines[] = '| Dimension | Continuously verified evidence |';
        $lines[] = '| --- | --- |';
        $lines[] = '| PHP | '.implode(', ', self::stringListField($matrix, 'php_ci')).' |';
        $lines[] = '| Laravel | '.implode(', ', self::stringListField($matrix, 'laravel_ci')).' |';
        $lines[] = '| Database | '.implode(', ', self::stringListField($matrix, 'database_ci')).' |';
        $lines[] = '';
        $lines[] = 'Platform levels:';
        $lines[] = '';

        foreach (self::listField($matrix, 'platforms') as $platform) {
            if (! self::isObject($platform)) {
                throw new RuntimeException('Validated platform entry unexpectedly changed shape.');
            }

            $lines[] = '- '.self::stringField($platform, 'name').': '.self::stringField($platform, 'level').'.';
        }

        $lines[] = '';
        $lines[] = 'GitHub-hosted macOS and native Windows runners continuously execute the independent PHP 8.4 consumer smoke. Database release evidence remains specific to Ubuntu.';
        $lines[] = '';
        $lines[] = 'The exact meaning and boundaries of these levels are in [the compatibility policy](compatibility.md).';
        $lines[] = '';
        $lines[] = '## Published policies';
        $lines[] = '';
        $lines[] = '| Policy | Document |';
        $lines[] = '| --- | --- |';

        foreach ($policies as $name => $path) {
            if (! is_string($path)) {
                throw new RuntimeException('Validated policy path unexpectedly changed type.');
            }

            $relativePath = str_starts_with($path, 'docs/') ? substr($path, 5) : '../'.$path;
            $lines[] = sprintf('| %s | [`%s`](%s) |', str_replace('_', ' ', $name), $path, $relativePath);
        }

        $lines[] = '';
        $lines[] = '## Artifact paths';
        $lines[] = '';
        $lines[] = '- Fresh install from deterministic Laravel/runtime ZIP artifacts: **'
            .self::stringField($artifacts, 'fresh_install').'** by `composer release:dry-run`.';
        $upgradeStatus = self::stringField($artifacts, 'upgrade_from_published_release');
        $upgradeExplanation = match ($upgradeStatus) {
            'blocked-no-published-baseline' => 'No tagged or Packagist baseline exists, so an upgrade claim would be synthetic.',
            'pending-from-published-beta' => '`v1.0.0-beta.1` is now the published baseline; a subsequent release must exercise the real upgrade.',
            'verified-from-published-artifacts' => 'A real published-artifact upgrade has been verified.',
            default => throw new RuntimeException('Validated upgrade status unexpectedly changed.'),
        };
        $lines[] = '- Upgrade from a previously published artifact: **'
            .$upgradeStatus.'**. '.$upgradeExplanation;
        $lines[] = '';
        $lines[] = '## External release gates and outcome';
        $lines[] = '';
        $lines[] = '| Gate | Tracking issue | Status |';
        $lines[] = '| --- | ---: | --- |';

        $gateIssues = [];
        $gateStatuses = [];

        foreach ($gates as $gate) {
            if (! self::isObject($gate)) {
                throw new RuntimeException('Validated release gate unexpectedly changed shape.');
            }

            $issue = self::integerField($gate, 'issue');
            $identifier = self::stringField($gate, 'id');
            $gateIssues[$identifier] = $issue;
            $gateStatuses[$identifier] = self::stringField($gate, 'status');
            $lines[] = sprintf(
                '| `%s` | [#%d](https://github.com/zerodycoder/RaceProof/issues/%d) | %s |',
                $identifier,
                $issue,
                $issue,
                self::stringField($gate, 'status'),
            );
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Package publication gate #%d is **%s**. Stable publication remains prohibited until beta-adoption gate #%d is verified and the published-artifact upgrade path exists. Issue #%d records the stable workflow outcome and is closed only after publication succeeds; it is reported here but is not a circular pre-publication predicate.',
            $gateIssues['public-package-publication'],
            $gateStatuses['public-package-publication'],
            $gateIssues['beta-adoption-evidence'],
            $gateIssues['stable-release'],
        );
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return list<string>
     */
    public static function stableGateErrors(string $root, array $audit): array
    {
        $errors = self::validationErrors($root, $audit);

        if ($errors !== []) {
            return $errors;
        }

        $artifacts = self::objectField($audit, 'artifacts');

        if (self::stringField($artifacts, 'upgrade_from_published_release') !== 'verified-from-published-artifacts') {
            $errors[] = 'The upgrade path from the previous published release is not verified.';
        }

        foreach (self::listField($audit, 'external_gates') as $gate) {
            if (! self::isObject($gate)) {
                throw new RuntimeException('Validated release gate unexpectedly changed shape.');
            }

            if (
                self::stringField($gate, 'id') !== 'stable-release'
                && self::stringField($gate, 'status') !== 'verified'
            ) {
                $errors[] = sprintf(
                    'External gate %s in issue #%d is not verified.',
                    self::stringField($gate, 'id'),
                    self::integerField($gate, 'issue'),
                );
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array{
     *     schema_version: 1,
     *     audit_definition_sha256: string,
     *     automated_controls: int,
     *     mutation_risk_hotspots: int,
     *     fresh_install: string,
     *     published_upgrade: string,
     *     release_status: 'eligible'|'blocked',
     *     blocked_issues: list<int>
     * }
     */
    public static function machineEvidence(array $audit): array
    {
        $controls = self::listField($audit, 'controls');
        $hotspots = array_filter(
            $controls,
            static fn (mixed $control): bool => self::isObject($control)
                && ($control['mutation_hotspot'] ?? null) === true,
        );
        $artifacts = self::objectField($audit, 'artifacts');
        $gates = self::listField($audit, 'external_gates');

        $blockedIssues = array_values(array_map(
            static function (array $gate): int {
                return self::integerField($gate, 'issue');
            },
            array_filter(
                $gates,
                static fn (mixed $gate): bool => self::isObject($gate)
                    && ($gate['status'] ?? null) !== 'verified',
            ),
        ));
        $upgradeStatus = self::stringField($artifacts, 'upgrade_from_published_release');

        return [
            'schema_version' => 1,
            'audit_definition_sha256' => hash(
                'sha256',
                json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'automated_controls' => count($controls),
            'mutation_risk_hotspots' => count($hotspots),
            'fresh_install' => self::stringField($artifacts, 'fresh_install'),
            'published_upgrade' => $upgradeStatus,
            'release_status' => $blockedIssues === [] && $upgradeStatus === 'verified-from-published-artifacts'
                ? 'eligible'
                : 'blocked',
            'blocked_issues' => $blockedIssues,
        ];
    }

    /** @param list<string> $errors */
    private static function controls(string $root, mixed $value, array &$errors): void
    {
        $controls = self::list($value, '$.controls', $errors);

        if ($controls === null) {
            return;
        }

        $identifiers = [];
        $hotspots = 0;

        foreach ($controls as $index => $control) {
            $path = "$.controls[{$index}]";
            $control = self::object($control, $path, $errors);

            if ($control === null) {
                continue;
            }

            self::exactKeys($control, ['id', 'scope', 'mutation_hotspot', 'sources', 'tests'], $path, $errors);
            $identifier = $control['id'] ?? null;

            if (! is_string($identifier) || preg_match('/^[a-z][a-z0-9-]+$/D', $identifier) !== 1) {
                $errors[] = "{$path}.id must be a kebab-case identifier.";
            } elseif (isset($identifiers[$identifier])) {
                $errors[] = "{$path}.id must be unique.";
            } else {
                $identifiers[$identifier] = true;
            }

            if (! is_string($control['scope'] ?? null) || trim($control['scope']) === '') {
                $errors[] = "{$path}.scope must be a non-empty string.";
            }

            if (! is_bool($control['mutation_hotspot'] ?? null)) {
                $errors[] = "{$path}.mutation_hotspot must be boolean.";
            } elseif ($control['mutation_hotspot'] === true) {
                $hotspots++;
            }

            $sources = self::stringList($control['sources'] ?? null, "{$path}.sources", $errors);

            if ($sources !== null) {
                if (($control['mutation_hotspot'] ?? null) === true && $sources === []) {
                    $errors[] = "{$path}.sources cannot be empty for a mutation hotspot.";
                }

                foreach ($sources as $sourceIndex => $source) {
                    self::repositoryFile($root, $source, "{$path}.sources[{$sourceIndex}]", $errors);
                }
            }

            $tests = self::list($control['tests'] ?? null, "{$path}.tests", $errors);

            if ($tests === null) {
                continue;
            }

            if ($tests === []) {
                $errors[] = "{$path}.tests must contain executable evidence.";
            }

            foreach ($tests as $testIndex => $test) {
                self::testReference($root, $test, "{$path}.tests[{$testIndex}]", $errors);
            }
        }

        $required = [
            'environment-database-safety',
            'worker-lifecycle',
            'redaction-reporting',
            'coordination-integrity',
            'production-runtime-boundary',
            'release-supply-chain',
            'published-contracts',
        ];

        foreach ($required as $identifier) {
            if (! isset($identifiers[$identifier])) {
                $errors[] = "$.controls is missing required control {$identifier}.";
            }
        }

        if ($hotspots < 5) {
            $errors[] = '$.controls must identify at least five mutation-risk hotspots.';
        }
    }

    /** @param list<string> $errors */
    private static function testReference(string $root, mixed $value, string $path, array &$errors): void
    {
        $test = self::object($value, $path, $errors);

        if ($test === null) {
            return;
        }

        self::exactKeys($test, ['file', 'method'], $path, $errors);
        $file = $test['file'] ?? null;
        $method = $test['method'] ?? null;

        if (! is_string($file)) {
            $errors[] = "{$path}.file must be a repository-relative path.";

            return;
        }

        self::repositoryFile($root, $file, "{$path}.file", $errors);

        if (! is_string($method) || preg_match('/^test_[a-z0-9_]+$/D', $method) !== 1) {
            $errors[] = "{$path}.method must name a test method.";

            return;
        }

        $contents = file_get_contents($root.'/'.$file);

        if (! is_string($contents) || preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $contents) !== 1) {
            $errors[] = "{$path}.method does not exist in {$file}.";
        }
    }

    /** @param list<string> $errors */
    private static function repositoryFile(string $root, string $file, string $path, array &$errors): void
    {
        if (
            $file === ''
            || str_contains($file, '..')
            || str_starts_with($file, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $file) === 1
            || ! is_file($root.'/'.$file)
        ) {
            $errors[] = "{$path} must resolve to a repository file.";
        }
    }

    /** @param list<string> $errors */
    private static function policies(string $root, mixed $value, array &$errors): void
    {
        $policies = self::object($value, '$.policies', $errors);

        if ($policies === null) {
            return;
        }

        self::exactKeys(
            $policies,
            ['compatibility', 'upgrade', 'security', 'maintenance', 'known_limitations'],
            '$.policies',
            $errors,
        );

        foreach ($policies as $name => $file) {
            if (! is_string($file)) {
                $errors[] = "$.policies.{$name} must be a repository-relative path.";
            } else {
                self::repositoryFile($root, $file, "$.policies.{$name}", $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private static function matrix(string $root, mixed $value, array &$errors): void
    {
        $matrix = self::object($value, '$.matrix', $errors);

        if ($matrix === null) {
            return;
        }

        self::exactKeys($matrix, ['php_ci', 'laravel_ci', 'database_ci', 'platforms'], '$.matrix', $errors);
        self::exactStringList($matrix['php_ci'] ?? null, ['8.2', '8.5'], '$.matrix.php_ci', $errors);
        self::exactStringList($matrix['laravel_ci'] ?? null, ['12', '13'], '$.matrix.laravel_ci', $errors);
        self::exactStringList(
            $matrix['database_ci'] ?? null,
            ['mysql:8.4', 'pgsql:17'],
            '$.matrix.database_ci',
            $errors,
        );

        $platforms = self::list($matrix['platforms'] ?? null, '$.matrix.platforms', $errors);

        if ($platforms !== null) {
            $expected = [
                'Ubuntu Linux' => 'continuous',
                'WSL2' => 'development',
                'macOS' => 'best-effort',
                'Native Windows' => 'experimental',
            ];
            $actual = [];

            foreach ($platforms as $index => $platform) {
                $path = "$.matrix.platforms[{$index}]";
                $platform = self::object($platform, $path, $errors);

                if ($platform === null) {
                    continue;
                }

                self::exactKeys($platform, ['name', 'level'], $path, $errors);
                $name = $platform['name'] ?? null;
                $level = $platform['level'] ?? null;

                if (is_string($name) && is_string($level)) {
                    $actual[$name] = $level;
                } else {
                    $errors[] = "{$path} must contain string name and level values.";
                }
            }

            if ($actual !== $expected) {
                $errors[] = '$.matrix.platforms does not match the documented support levels.';
            }
        }

        self::composerMatrix($root, $errors);
    }

    /** @param list<string> $errors */
    private static function composerMatrix(string $root, array &$errors): void
    {
        $rootManifest = self::jsonObject($root.'/composer.json', $errors);
        $runtimeManifest = self::jsonObject($root.'/runtime/composer.json', $errors);

        if ($rootManifest === null || $runtimeManifest === null) {
            return;
        }

        $requirements = self::isObject($rootManifest['require'] ?? null) ? $rootManifest['require'] : [];
        $runtimeRequirements = self::isObject($runtimeManifest['require'] ?? null)
            ? $runtimeManifest['require']
            : [];

        if (($requirements['php'] ?? null) !== '^8.2') {
            $errors[] = 'composer.json PHP support must remain ^8.2.';
        }

        if (($runtimeRequirements['php'] ?? null) !== '^8.2') {
            $errors[] = 'runtime/composer.json PHP support must remain ^8.2.';
        }

        foreach (['auth', 'console', 'database', 'http', 'support'] as $component) {
            if (($requirements["illuminate/{$component}"] ?? null) !== '^12.0 || ^13.0') {
                $errors[] = "composer.json illuminate/{$component} support must remain ^12.0 || ^13.0.";
            }
        }
    }

    /** @param list<string> $errors */
    private static function artifacts(mixed $value, array &$errors): void
    {
        $artifacts = self::object($value, '$.artifacts', $errors);

        if ($artifacts === null) {
            return;
        }

        self::exactKeys(
            $artifacts,
            ['fresh_install', 'upgrade_from_published_release'],
            '$.artifacts',
            $errors,
        );

        if (($artifacts['fresh_install'] ?? null) !== 'automated') {
            $errors[] = '$.artifacts.fresh_install must be automated.';
        }

        if (! in_array(
            $artifacts['upgrade_from_published_release'] ?? null,
            [
                'blocked-no-published-baseline',
                'pending-from-published-beta',
                'verified-from-published-artifacts',
            ],
            true,
        )) {
            $errors[] = '$.artifacts.upgrade_from_published_release has an unsupported status.';
        }
    }

    /** @param list<string> $errors */
    private static function externalGates(mixed $value, array &$errors): void
    {
        $gates = self::list($value, '$.external_gates', $errors);

        if ($gates === null) {
            return;
        }

        $expected = [
            'public-package-publication',
            'beta-adoption-evidence',
            'stable-release',
        ];
        $actual = [];
        $issues = [];

        foreach ($gates as $index => $gate) {
            $path = "$.external_gates[{$index}]";
            $gate = self::object($gate, $path, $errors);

            if ($gate === null) {
                continue;
            }

            self::exactKeys($gate, ['id', 'issue', 'status'], $path, $errors);
            $identifier = $gate['id'] ?? null;
            $issue = $gate['issue'] ?? null;

            if (! is_string($identifier) || ! is_int($issue) || $issue < 1) {
                $errors[] = "{$path} must contain a string id and positive integer issue.";
            } else {
                $actual[] = $identifier;
                $issues[] = $issue;
            }

            if (! in_array($gate['status'] ?? null, ['blocked', 'verified'], true)) {
                $errors[] = "{$path}.status must be blocked or verified.";
            }
        }

        if ($actual !== $expected) {
            $errors[] = '$.external_gates must track the package-publication, beta-adoption, and stable-release gates in order.';
        }

        if (count(array_unique($issues)) !== count($issues)) {
            $errors[] = '$.external_gates issue numbers must be unique.';
        }
    }

    /** @param list<string> $errors */
    private static function workflowSupplyChain(string $root, array &$errors): void
    {
        foreach (['.github/workflows/tests.yml', '.github/workflows/release.yml'] as $file) {
            $contents = file_get_contents($root.'/'.$file);

            if (! is_string($contents)) {
                $errors[] = "Unable to read {$file}.";

                continue;
            }

            preg_match_all('/uses:\s+[^\\s]+@([^\\s#]+)/', $contents, $matches);

            if ($matches[1] === []) {
                $errors[] = "{$file} contains no auditable action references.";
            }

            foreach ($matches[1] as $reference) {
                if (preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
                    $errors[] = "{$file} action reference {$reference} is not pinned to a full commit.";
                }
            }
        }

        $testsWorkflow = file_get_contents($root.'/.github/workflows/tests.yml');
        $releaseWorkflow = file_get_contents($root.'/.github/workflows/release.yml');

        if (! is_string($testsWorkflow) || ! str_contains($testsWorkflow, 'release-audit:')) {
            $errors[] = '.github/workflows/tests.yml must define the release-audit job.';
        }

        foreach ([
            'secret-scan:',
            'fetch-depth: 0',
            'gitleaks dir . --no-banner --redact',
            'gitleaks git . --no-banner --redact',
            "php: '8.2'",
            "php: '8.5'",
            'mutation:',
            'composer test:mutation',
            'tools/check-mutation.php',
            'consumer:',
            'tests/ConsumerApp',
            'redis:',
            'image: redis:7.4-alpine',
            'composer test:redis',
            'platform-smoke:',
            'macos-latest',
            'windows-latest',
            '- mutation',
            '- redis',
            '- platform-smoke',
            'image: mysql:8.4',
            'image: postgres:17',
        ] as $needle) {
            if (! is_string($testsWorkflow) || ! str_contains($testsWorkflow, $needle)) {
                $errors[] = ".github/workflows/tests.yml is missing {$needle}.";
            }
        }

        if (! is_string($releaseWorkflow) || ! str_contains($releaseWorkflow, "grep -Fx 'release-audit'")) {
            $errors[] = '.github/workflows/release.yml must require the exact-head release-audit check.';
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private static function jsonObject(string $path, array &$errors): ?array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            $errors[] = "Unable to read {$path}.";

            return null;
        }

        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = "Invalid JSON in {$path}: {$exception->getMessage()}.";

            return null;
        }

        if (! self::isObject($value)) {
            $errors[] = "{$path} must contain a JSON object.";

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $errors
     */
    private static function exactStringList(mixed $value, array $expected, string $path, array &$errors): void
    {
        $actual = self::stringList($value, $path, $errors);

        if ($actual !== null && $actual !== $expected) {
            $errors[] = "{$path} does not match the supported CI matrix.";
        }
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>|null
     */
    private static function stringList(mixed $value, string $path, array &$errors): ?array
    {
        $values = self::list($value, $path, $errors);

        if ($values === null) {
            return null;
        }

        $strings = [];

        foreach ($values as $index => $item) {
            if (! is_string($item) || $item === '') {
                $errors[] = "{$path}[{$index}] must be a non-empty string.";
            } else {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /** @param list<string> $errors */
    private static function date(mixed $value, string $path, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[] = "{$path} must be a YYYY-MM-DD date.";

            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = "{$path} must be a valid YYYY-MM-DD date.";
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private static function exactKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff($allowed, array_keys($value)) as $key) {
            $errors[] = "{$path}.{$key} is required.";
        }

        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $errors[] = "{$path}.{$key} is not allowed.";
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
            throw new RuntimeException("Validated release audit field {$field} is not an object.");
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
            throw new RuntimeException("Validated release audit field {$field} is not a list.");
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
                throw new RuntimeException("Validated release audit field {$field} contains a non-string.");
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
            throw new RuntimeException("Validated release audit field {$field} is not a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    private static function integerField(array $object, string $field): int
    {
        $value = $object[$field] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("Validated release audit field {$field} is not an integer.");
        }

        return $value;
    }
}
