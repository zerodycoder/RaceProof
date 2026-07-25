<?php

declare(strict_types=1);

use RaceProof\BetaTools\BetaEvidence;

require_once __DIR__.'/BetaEvidence.php';

$root = dirname(__DIR__, 2);
$registry = BetaEvidence::load($root.'/beta/evidence.json');
$errors = BetaEvidence::validationErrors($registry);

if ($errors !== []) {
    fwrite(STDERR, "Invalid public beta evidence:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

$path = $root.'/docs/beta-evidence.md';

if (file_put_contents($path, BetaEvidence::render($registry), LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write {$path}.\n");
    exit(1);
}

fwrite(STDOUT, "Updated docs/beta-evidence.md from consented public evidence.\n");
