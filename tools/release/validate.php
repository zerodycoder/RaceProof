<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$version = Release::argument(1);
$tag = Release::argument(2, 'v'.$version);

try {
    Release::version($version);

    if ($tag !== 'v'.$version) {
        throw new RuntimeException("Tag {$tag} does not match version {$version}.");
    }

    /** @var array<string, mixed> $laravel */
    $laravel = json_decode(
        (string) file_get_contents($root.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $expectedConstraint = Release::runtimeConstraint($version);
    $requirements = $laravel['require'] ?? null;
    $repositories = $laravel['repositories'] ?? null;
    $repository = is_array($repositories) ? ($repositories[0] ?? null) : null;
    $options = is_array($repository) ? ($repository['options'] ?? null) : null;
    $versions = is_array($options) ? ($options['versions'] ?? null) : null;
    $actualConstraint = is_array($requirements) ? ($requirements['raceproof/runtime'] ?? null) : null;
    $pathVersion = is_array($versions) ? ($versions['raceproof/runtime'] ?? null) : null;

    if ($actualConstraint !== $expectedConstraint) {
        throw new RuntimeException(
            "raceproof/laravel must require raceproof/runtime {$expectedConstraint}; found "
            .var_export($actualConstraint, true).'.',
        );
    }

    if ($pathVersion !== $version) {
        throw new RuntimeException(
            "The monorepo path version must be {$version}; found ".var_export($pathVersion, true).'.',
        );
    }

    $changelog = file_get_contents($root.'/CHANGELOG.md');

    if (! is_string($changelog) || preg_match(
        '/^## \['.preg_quote($version, '/').'\] - \d{4}-\d{2}-\d{2}$/m',
        $changelog,
    ) !== 1) {
        throw new RuntimeException(
            "CHANGELOG.md must contain a dated `## [{$version}] - YYYY-MM-DD` section.",
        );
    }

    echo "Release metadata is aligned for {$tag}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
