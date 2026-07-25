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

$expectedReport = BetaEvidence::render($registry);
$actualReport = file_get_contents($root.'/docs/beta-evidence.md');

if (! is_string($actualReport) || $actualReport !== $expectedReport) {
    fwrite(
        STDERR,
        "docs/beta-evidence.md is stale. Run `composer beta:report` and review the consented output.\n",
    );
    exit(1);
}

fwrite(STDOUT, "Beta evidence is valid; publication gates remain evidence-dependent.\n");
