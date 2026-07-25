<?php

declare(strict_types=1);

use RaceProof\BetaTools\BetaEvidence;

require_once __DIR__.'/BetaEvidence.php';

$root = dirname(__DIR__, 2);
$registry = BetaEvidence::load($root.'/beta/evidence.json');
$errors = BetaEvidence::releaseGateErrors($registry);
$report = is_file($root.'/docs/beta-evidence.md')
    ? file_get_contents($root.'/docs/beta-evidence.md')
    : false;

if ($errors === [] && $report !== BetaEvidence::render($registry)) {
    $errors[] = 'The generated beta evidence report is stale.';
}

if ($errors !== []) {
    fwrite(STDERR, "Private-beta release gates are not met:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Private-beta release gates are backed by reviewed, consented evidence.\n");
