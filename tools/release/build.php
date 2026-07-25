<?php

declare(strict_types=1);

use RaceProof\ReleaseTools\Release;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/Release.php';

$version = Release::argument(1);
$directory = Release::argument(2, $root.'/build/release/'.$version);

try {
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $directory) !== 1) {
        $directory = $root.'/'.$directory;
    }

    $artifacts = Release::buildArtifacts($root, $version, $directory);
    echo json_encode($artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
