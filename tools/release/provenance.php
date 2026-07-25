<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$version = Release::argument(1);
$sourceCommit = Release::argument(2);
$runtimeCommit = Release::argument(3);
$repository = Release::argument(4);
$runId = Release::argument(5);
$directory = Release::argument(6);

try {
    Release::version($version);

    foreach ([
        'source commit' => $sourceCommit,
        'runtime commit' => $runtimeCommit,
    ] as $label => $commit) {
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw new RuntimeException("Invalid {$label}: {$commit}");
        }
    }

    if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1) {
        throw new RuntimeException("Invalid source repository: {$repository}");
    }

    if ($runId === '' || ! is_dir($directory)) {
        throw new RuntimeException('A workflow run ID and artifact directory are required.');
    }

    $packages = [];

    foreach (['laravel', 'runtime'] as $package) {
        $filename = "raceproof-{$package}-{$version}.zip";
        $path = $directory.'/'.$filename;
        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException("Unable to hash {$path}.");
        }

        $packages["raceproof/{$package}"] = [
            'artifact' => $filename,
            'sha256' => $hash,
            'version' => $version,
        ];
    }

    Release::writeJson($directory.'/provenance.json', [
        'schema_version' => 1,
        'source' => [
            'repository' => 'https://github.com/'.$repository,
            'commit' => $sourceCommit,
            'tag' => 'v'.$version,
        ],
        'runtime_split' => [
            'repository' => 'https://github.com/'.(getenv('RUNTIME_SPLIT_REPOSITORY') ?: '[configured at release time]'),
            'commit' => $runtimeCommit,
            'tag' => 'v'.$version,
        ],
        'builder' => [
            'workflow' => 'https://github.com/'.$repository.'/actions/runs/'.$runId,
            'event' => 'push',
        ],
        'packages' => $packages,
    ]);

    echo $directory."/provenance.json\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
