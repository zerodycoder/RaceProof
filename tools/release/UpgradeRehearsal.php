<?php

declare(strict_types=1);

namespace RaceProof\ReleaseTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class UpgradeRehearsal
{
    private const PACKAGES = [
        'raceproof/runtime',
        'raceproof/laravel',
    ];

    public static function assertVersionProgression(string $baseline, string $candidate): void
    {
        Release::version($baseline);
        Release::version($candidate);

        if (version_compare($candidate, $baseline, '<=')) {
            throw new RuntimeException(
                "Upgrade candidate {$candidate} must be newer than published baseline {$baseline}.",
            );
        }
    }

    public static function assertCleanSource(string $status): void
    {
        if (trim($status) !== '') {
            throw new RuntimeException(
                'Published upgrade rehearsal requires a clean source checkout.',
            );
        }
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function baselineManifest(array $manifest, string $baseline): array
    {
        Release::version($baseline);
        $requirements = $manifest['require'] ?? null;
        $devRequirements = $manifest['require-dev'] ?? null;

        if (! is_array($requirements) || ! is_array($devRequirements)) {
            throw new RuntimeException('Upgrade fixture manifest must contain require and require-dev objects.');
        }

        $requirements['raceproof/runtime'] = $baseline;
        $devRequirements['raceproof/laravel'] = $baseline;
        $manifest['require'] = $requirements;
        $manifest['require-dev'] = $devRequirements;
        $manifest['minimum-stability'] = Release::minimumStability($baseline);
        $manifest['prefer-stable'] = true;
        unset($manifest['repositories']);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function candidateManifest(
        array $manifest,
        string $candidate,
        string $artifactDirectory,
    ): array {
        Release::version($candidate);
        $requirements = $manifest['require'] ?? null;
        $devRequirements = $manifest['require-dev'] ?? null;

        if (! is_array($requirements) || ! is_array($devRequirements)) {
            throw new RuntimeException('Upgrade fixture manifest must contain require and require-dev objects.');
        }

        $requirements['raceproof/runtime'] = $candidate;
        $devRequirements['raceproof/laravel'] = $candidate;
        $manifest['repositories'] = [[
            'type' => 'artifact',
            'url' => str_replace('\\', '/', $artifactDirectory),
            'canonical' => true,
        ]];
        $manifest['require'] = $requirements;
        $manifest['require-dev'] = $devRequirements;
        $manifest['minimum-stability'] = Release::minimumStability($candidate);
        $manifest['prefer-stable'] = true;

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return array<string, array{
     *     version:string,
     *     source_reference:string|null,
     *     dist_reference:string|null,
     *     dist_type:string|null
     * }>
     */
    public static function packageEvidence(
        array $lock,
        string $expectedVersion,
        bool $requirePublishedReferences,
    ): array {
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $records = $lock[$section] ?? null;

            if (! is_array($records)) {
                throw new RuntimeException("Composer lock {$section} must be a list.");
            }

            foreach ($records as $record) {
                if (! is_array($record) || ! is_string($record['name'] ?? null)) {
                    continue;
                }

                $name = $record['name'];

                if (! in_array($name, self::PACKAGES, true)) {
                    continue;
                }

                if (isset($packages[$name])) {
                    throw new RuntimeException("Composer lock contains duplicate package {$name}.");
                }

                $version = $record['version'] ?? null;

                if (! is_string($version) || ltrim($version, 'v') !== ltrim($expectedVersion, 'v')) {
                    throw new RuntimeException(
                        "Composer lock must contain {$name} {$expectedVersion}; found "
                        .var_export($version, true).'.',
                    );
                }

                $source = is_array($record['source'] ?? null) ? $record['source'] : [];
                $dist = is_array($record['dist'] ?? null) ? $record['dist'] : [];
                $sourceReference = is_string($source['reference'] ?? null)
                    ? $source['reference']
                    : null;
                $distReference = is_string($dist['reference'] ?? null)
                    ? $dist['reference']
                    : null;
                $distType = is_string($dist['type'] ?? null) ? $dist['type'] : null;

                if (
                    $requirePublishedReferences
                    && (
                        $sourceReference === null
                        || preg_match('/^[0-9a-f]{40}$/D', $sourceReference) !== 1
                        || $distReference === null
                        || preg_match('/^[0-9a-f]{40}$/D', $distReference) !== 1
                        || $distType !== 'zip'
                    )
                ) {
                    throw new RuntimeException(
                        "Published package {$name} must have immutable source and dist references.",
                    );
                }

                if (! $requirePublishedReferences && $distType !== 'zip') {
                    throw new RuntimeException(
                        "Candidate package {$name} must be installed from an artifact ZIP.",
                    );
                }

                $packages[$name] = [
                    'version' => ltrim($version, 'v'),
                    'source_reference' => $sourceReference,
                    'dist_reference' => $distReference,
                    'dist_type' => $distType,
                ];
            }
        }

        foreach (self::PACKAGES as $name) {
            if (! isset($packages[$name])) {
                throw new RuntimeException("Composer lock does not contain required package {$name}.");
            }
        }

        return [
            'raceproof/runtime' => $packages['raceproof/runtime'],
            'raceproof/laravel' => $packages['raceproof/laravel'],
        ];
    }

    /**
     * @param array<string, array{
     *     version:string,
     *     source_reference:string|null,
     *     dist_reference:string|null,
     *     dist_type:string|null
     * }> $baselinePackages
     */
    public static function assertDistinctCandidateSource(
        string $candidateCommit,
        array $baselinePackages,
    ): void {
        if (preg_match('/^[0-9a-f]{40}$/D', $candidateCommit) !== 1) {
            throw new RuntimeException('Candidate source commit must be a full lowercase Git SHA.');
        }

        $baselineCommit = $baselinePackages['raceproof/laravel']['source_reference'] ?? null;

        if (! is_string($baselineCommit) || $baselineCommit === '') {
            throw new RuntimeException('Published Laravel baseline source reference is missing.');
        }

        if (hash_equals($baselineCommit, $candidateCommit)) {
            throw new RuntimeException(
                'Candidate source commit must differ from the published Laravel baseline.',
            );
        }
    }

    /** @return array<string, mixed> */
    public static function loadJson(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public static function copyConsumerFixture(string $source, string $destination): void
    {
        if (! is_dir($source) || is_dir($destination)) {
            throw new RuntimeException('Upgrade fixture source must exist and destination must be absent.');
        }

        if (! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new RuntimeException("Unable to create upgrade fixture {$destination}.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                throw new RuntimeException('Upgrade fixture copy encountered an invalid entry.');
            }

            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));

            if (self::excludedFixturePath($relative)) {
                continue;
            }

            $target = $destination.'/'.$relative;

            if ($entry->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0777, true) && ! is_dir($target)) {
                    throw new RuntimeException("Unable to create upgrade fixture directory {$target}.");
                }

                continue;
            }

            $parent = dirname($target);

            if (! is_dir($parent) && ! mkdir($parent, 0777, true) && ! is_dir($parent)) {
                throw new RuntimeException("Unable to create upgrade fixture directory {$parent}.");
            }

            if (! copy($entry->getPathname(), $target)) {
                throw new RuntimeException("Unable to copy upgrade fixture file {$relative}.");
            }
        }
    }

    private static function excludedFixturePath(string $relative): bool
    {
        if (
            (
                str_starts_with($relative, 'bootstrap/cache/')
                || str_starts_with($relative, 'storage/')
            )
            && basename($relative) !== '.gitignore'
        ) {
            return true;
        }

        foreach ([
            '.env',
            '.phpunit.cache',
            'composer.lock',
            'database/consumer.sqlite',
            'vendor',
        ] as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    private function __construct() {}
}
