<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;
use RaceProof\ReleaseTools\UpgradeRehearsal;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';
require __DIR__.'/UpgradeRehearsal.php';

$configuredBaseline = getenv('RACEPROOF_UPGRADE_BASELINE');
$configuredCandidate = getenv('RACEPROOF_UPGRADE_CANDIDATE');
$baseline = is_string($configuredBaseline) && $configuredBaseline !== ''
    ? $configuredBaseline
    : '1.0.0-beta.1';
$candidate = Release::argument(
    1,
    is_string($configuredCandidate) && $configuredCandidate !== ''
        ? $configuredCandidate
        : '1.0.0-rc.1',
);
$output = $root.'/build/release/upgrade/'.$candidate;
$artifacts = $output.'/artifacts';
$fixture = $output.'/application';

try {
    UpgradeRehearsal::assertVersionProgression($baseline, $candidate);
    UpgradeRehearsal::assertCleanSource(Release::run(
        ['git', 'status', '--porcelain=v1', '--untracked-files=all'],
        $root,
    ));
    Release::resetDirectory($root, $output);
    $built = Release::buildArtifacts($root, $candidate, $artifacts);
    UpgradeRehearsal::copyConsumerFixture($root.'/tests/ConsumerApp', $fixture);

    $sourceManifest = UpgradeRehearsal::loadJson($fixture.'/composer.json');
    $baselineManifest = UpgradeRehearsal::baselineManifest($sourceManifest, $baseline);
    Release::writeJson($fixture.'/composer.json', $baselineManifest);
    Release::run(
        [
            'composer',
            'update',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
        ],
        $fixture,
        600,
    );

    $baselinePackages = UpgradeRehearsal::packageEvidence(
        UpgradeRehearsal::loadJson($fixture.'/composer.lock'),
        $baseline,
        true,
    );
    $candidateCommit = trim(Release::run(['git', 'rev-parse', 'HEAD'], $root));
    UpgradeRehearsal::assertDistinctCandidateSource($candidateCommit, $baselinePackages);
    $baselineSmoke = runUpgradeSmoke($fixture);

    $candidateManifest = UpgradeRehearsal::candidateManifest(
        UpgradeRehearsal::loadJson($fixture.'/composer.json'),
        $candidate,
        $artifacts,
    );
    Release::writeJson($fixture.'/composer.json', $candidateManifest);
    Release::run(
        [
            'composer',
            'update',
            'raceproof/runtime',
            'raceproof/laravel',
            '--with-dependencies',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
        ],
        $fixture,
        600,
    );
    Release::run(
        ['composer', 'audit', '--locked', '--no-interaction'],
        $fixture,
        180,
    );

    $candidatePackages = UpgradeRehearsal::packageEvidence(
        UpgradeRehearsal::loadJson($fixture.'/composer.lock'),
        $candidate,
        false,
    );
    $candidateSmoke = runUpgradeSmoke($fixture);
    $evidence = [
        'schema_version' => 1,
        'baseline' => [
            'version' => $baseline,
            'packages' => $baselinePackages,
            'smoke' => $baselineSmoke,
        ],
        'candidate' => [
            'version' => $candidate,
            'source_commit' => $candidateCommit,
            'artifacts' => [
                'raceproof/laravel' => $built['laravel']['sha256'],
                'raceproof/runtime' => $built['runtime']['sha256'],
            ],
            'packages' => $candidatePackages,
            'smoke' => $candidateSmoke,
        ],
        'upgrade' => [
            'from' => $baseline,
            'to' => $candidate,
            'distinct_source' => true,
            'both_packages_updated' => true,
        ],
    ];
    Release::writeJson($output.'/evidence.json', $evidence);

    echo "Published upgrade rehearsal passed: {$baseline} -> {$candidate}.\n";
    echo "Candidate source: {$candidateCommit}\n";
    echo "Evidence: {$output}/evidence.json\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

/** @return array{doctor_schema_version:int, doctor_ok:bool, runtime_inactive:bool, race_smoke:bool} */
function runUpgradeSmoke(string $fixture): array
{
    Release::run(['composer', 'run', 'prepare'], $fixture, 180);
    $doctorOutput = Release::run(
        [PHP_BINARY, 'artisan', 'raceproof:doctor', '--json', '--self-test'],
        $fixture,
        180,
    );
    /** @var array<string, mixed> $doctor */
    $doctor = json_decode(trim($doctorOutput), true, 32, JSON_THROW_ON_ERROR);

    if (($doctor['schema_version'] ?? null) !== 1 || ($doctor['ok'] ?? null) !== true) {
        throw new RuntimeException('Upgrade fixture Doctor contract failed.');
    }

    $autoload = var_export($fixture.'/vendor/autoload.php', true);
    $runtimeSmoke = "require {$autoload};"
        ."if (! function_exists('race_point')) { exit(2); }"
        ."race_point('upgrade-smoke');"
        .'if (RaceProof\\Runtime\\Checkpoint::active()) { exit(3); }'
        ."echo 'runtime inactive';";
    $runtimeOutput = Release::run([PHP_BINARY, '-r', $runtimeSmoke], $fixture, 60);

    if (trim($runtimeOutput) !== 'runtime inactive') {
        throw new RuntimeException('Upgrade fixture runtime no-op contract failed.');
    }

    Release::run(
        [PHP_BINARY, 'artisan', 'test', '--filter=PublishedUpgradeSmokeTest'],
        $fixture,
        180,
    );

    return [
        'doctor_schema_version' => 1,
        'doctor_ok' => true,
        'runtime_inactive' => true,
        'race_smoke' => true,
    ];
}
