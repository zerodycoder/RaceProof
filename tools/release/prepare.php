<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$version = Release::argument(1);

try {
    $constraint = Release::runtimeConstraint($version);
    $composerPath = $root.'/composer.json';
    /** @var array<string, mixed> $composer */
    $composer = json_decode(
        (string) file_get_contents($composerPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $repositories = $composer['repositories'] ?? null;
    $repository = is_array($repositories) ? ($repositories[0] ?? null) : null;
    $options = is_array($repository) ? ($repository['options'] ?? null) : null;
    $versions = is_array($options) ? ($options['versions'] ?? null) : null;
    $requirements = $composer['require'] ?? null;

    if (
        ! is_array($repositories)
        || ! is_array($repository)
        || ! is_array($options)
        || ! is_array($versions)
        || ! is_array($requirements)
    ) {
        throw new RuntimeException('composer.json does not contain the expected runtime path repository shape.');
    }

    $versions['raceproof/runtime'] = $version;
    $options['versions'] = $versions;
    $repository['options'] = $options;
    $repositories[0] = $repository;
    $requirements['raceproof/runtime'] = $constraint;
    $composer['repositories'] = $repositories;
    $composer['require'] = $requirements;
    Release::writeJson($composerPath, $composer);

    $consumerPath = $root.'/tests/ConsumerApp/composer.json';
    /** @var array<string, mixed> $consumer */
    $consumer = json_decode(
        (string) file_get_contents($consumerPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $consumer = Release::alignConsumerManifest($consumer, $version);
    Release::writeJson($consumerPath, $consumer);

    foreach ([
        'README.md',
        'docs/five-minute-guide.md',
        'docs/production-safety.md',
        'docs/runtime-checkpoints.md',
        'runtime/README.md',
    ] as $relativePath) {
        $path = $root.'/'.$relativePath;
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        $updated = Release::updateInstallationConstraints($contents, $version);

        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new RuntimeException("Unable to update {$path}.");
        }
    }

    echo "Prepared package metadata for {$version}.\n";
    echo "Next: add the dated changelog section, refresh the root lock, and install the consumer fixture.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
