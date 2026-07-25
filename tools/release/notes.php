<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$version = Release::argument(1);
$output = Release::argument(2);

try {
    Release::version($version);
    $changelog = file_get_contents($root.'/CHANGELOG.md');

    if (! is_string($changelog)) {
        throw new RuntimeException('Unable to read CHANGELOG.md.');
    }

    $pattern = '/^## \['.preg_quote($version, '/').'\] - \d{4}-\d{2}-\d{2}\R(?<notes>.*?)(?=^## |\z)/ms';

    if (preg_match($pattern, $changelog, $matches) !== 1) {
        throw new RuntimeException("No dated changelog section exists for {$version}.");
    }

    $notes = "# RaceProof {$version}\n\n".trim($matches['notes'])."\n\n"
        ."## Verification\n\n"
        ."- The release workflow accepts only a signed `v{$version}` tag whose commit is on `main`.\n"
        ."- Required CI checks must be successful on the exact release commit.\n"
        ."- Both Composer artifacts are built reproducibly, installed together in a clean fixture, and covered by signed SHA-256 checksums.\n"
        ."- `raceproof/runtime` is split, tagged, released, and observed on Packagist before `raceproof/laravel` is published.\n\n"
        ."See `provenance.json` and `SHA256SUMS.asc` in the release assets for exact source and artifact identities.\n";

    if ($output === '') {
        echo $notes;
    } elseif (file_put_contents($output, $notes, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write {$output}.");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
