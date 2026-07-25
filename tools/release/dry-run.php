<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$configuredVersion = getenv('RACEPROOF_RELEASE_DRY_RUN_VERSION');
$version = Release::argument(1, is_string($configuredVersion) && $configuredVersion !== ''
    ? $configuredVersion
    : '1.0.0-beta.1');
$output = $root.'/build/release/dry-run/'.$version;
$repeat = $root.'/build/release/repeat/'.$version;
$fixture = $root.'/build/release/install/'.$version;

try {
    $parsedVersion = Release::version($version);
    $minimumStability = Release::minimumStability($version);
    $installConstraint = '^'.$parsedVersion['major'].'.'.$parsedVersion['minor'].'@'.$minimumStability;
    $first = Release::buildArtifacts($root, $version, $output);
    $second = Release::buildArtifacts($root, $version, $repeat);

    foreach (['laravel', 'runtime'] as $package) {
        if ($first[$package]['sha256'] !== $second[$package]['sha256']) {
            throw new RuntimeException("The {$package} archive is not reproducible.");
        }
    }

    Release::resetDirectory($root, $fixture);
    Release::writeJson($fixture.'/composer.json', [
        'name' => 'raceproof/release-smoke',
        'license' => 'proprietary',
        'repositories' => [
            ['type' => 'artifact', 'url' => str_replace('\\', '/', $output)],
        ],
        'require' => [
            'raceproof/laravel' => $installConstraint,
        ],
        'config' => [
            'allow-plugins' => false,
            'audit' => ['abandoned' => 'fail'],
        ],
        'minimum-stability' => $minimumStability,
        'prefer-stable' => true,
    ]);
    Release::run(
        ['composer', 'validate', '--strict', '--no-check-publish'],
        $fixture,
    );
    Release::run(
        ['composer', 'install', '--prefer-dist', '--no-dev', '--no-interaction', '--no-progress', '--no-scripts'],
        $fixture,
    );
    $autoload = var_export($fixture.'/vendor/autoload.php', true);
    $smoke = "require {$autoload};"
        ."if (! function_exists('race') || ! function_exists('race_point')) { exit(2); }"
        ."race_point('release-smoke');"
        .'if (RaceProof\\Runtime\\Checkpoint::active()) { exit(3); }'
        ."echo 'artifact install ok';";
    $smokeOutput = Release::run([PHP_BINARY, '-r', $smoke], $fixture);

    echo "Release dry-run passed for {$version}.\n";
    echo trim($smokeOutput)."\n";
    echo "raceproof/laravel {$first['laravel']['sha256']}\n";
    echo "raceproof/runtime {$first['runtime']['sha256']}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
