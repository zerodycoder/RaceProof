<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$command = Release::argument(1);

try {
    switch ($command) {
        case 'update':
            updatePackage(Release::argument(2));
            break;
        case 'wait':
            waitForVersion(Release::argument(2), Release::argument(3));
            break;
        case 'install':
            installPublishedPackages($root, Release::argument(2));
            break;
        default:
            throw new RuntimeException('Usage: packagist.php update <repository> | wait <package> <version> | install <version>');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

function updatePackage(string $repository): void
{
    if (filter_var($repository, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException("Invalid repository URL: {$repository}");
    }

    $username = getenv('PACKAGIST_USERNAME');
    $token = getenv('PACKAGIST_API_TOKEN');

    if (! is_string($username) || $username === '' || ! is_string($token) || $token === '') {
        throw new RuntimeException('PACKAGIST_USERNAME and PACKAGIST_API_TOKEN are required.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Authorization: Bearer '.$username.':'.$token,
                'Content-Type: application/json',
            ]),
            'content' => json_encode(['repository' => $repository], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);
    $response = file_get_contents('https://packagist.org/api/update-package', false, $context);

    if (! is_string($response)) {
        throw new RuntimeException('Packagist update request failed without a response.');
    }

    /** @var array<string, mixed> $payload */
    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    if (($payload['status'] ?? null) !== 'success') {
        throw new RuntimeException('Packagist rejected the update: '.$response);
    }

    echo "Packagist update accepted for {$repository}.\n";
}

function waitForVersion(string $package, string $version): void
{
    if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $package) !== 1) {
        throw new RuntimeException("Invalid package name: {$package}");
    }

    Release::version($version);
    $deadline = time() + 300;

    do {
        $response = @file_get_contents(
            'https://repo.packagist.org/p2/'.$package.'.json?raceproof='.rawurlencode((string) time()),
        );

        if (is_string($response)) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $packages = $payload['packages'] ?? null;

            if (is_array($packages) && is_array($packages[$package] ?? null)) {
                $versions = $packages[$package];

                foreach ($versions as $metadata) {
                    $metadataVersion = is_array($metadata) ? ($metadata['version'] ?? null) : null;

                    if (is_string($metadataVersion) && ltrim($metadataVersion, 'v') === $version) {
                        echo "Packagist exposes {$package} {$version}.\n";

                        return;
                    }
                }
            }
        }

        sleep(10);
    } while (time() < $deadline);

    throw new RuntimeException("Packagist did not expose {$package} {$version} within five minutes.");
}

function installPublishedPackages(string $root, string $version): void
{
    Release::version($version);
    $fixture = $root.'/build/release/published/'.$version;
    Release::resetDirectory($root, $fixture);
    Release::writeJson($fixture.'/composer.json', [
        'name' => 'raceproof/published-release-smoke',
        'license' => 'proprietary',
        'require' => [
            'raceproof/laravel' => $version,
            'raceproof/runtime' => $version,
        ],
        'config' => [
            'allow-plugins' => false,
            'audit' => ['abandoned' => 'fail'],
        ],
        'minimum-stability' => Release::minimumStability($version),
        'prefer-stable' => true,
    ]);
    Release::run(
        ['composer', 'install', '--prefer-dist', '--no-dev', '--no-interaction', '--no-progress', '--no-scripts'],
        $fixture,
    );
    Release::run(['composer', 'audit', '--locked', '--no-interaction'], $fixture);
    $autoload = var_export($fixture.'/vendor/autoload.php', true);
    $smoke = "require {$autoload};"
        ."if (! function_exists('race') || ! function_exists('race_point')) { exit(2); }"
        ."race_point('published-release-smoke');"
        .'if (RaceProof\\Runtime\\Checkpoint::active()) { exit(3); }';
    Release::run([PHP_BINARY, '-r', $smoke], $fixture);

    echo "Published packages {$version} install together and retain the runtime no-op contract.\n";
}
