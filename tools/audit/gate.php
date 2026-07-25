<?php

declare(strict_types=1);

use RaceProof\AuditTools\ReleaseAudit;
use RaceProof\BetaTools\BetaEvidence;

$root = dirname(__DIR__, 2);
require_once __DIR__.'/ReleaseAudit.php';
require_once $root.'/tools/beta/BetaEvidence.php';

$audit = ReleaseAudit::load($root.'/audit/release-audit.json');
$errors = ReleaseAudit::stableGateErrors($root, $audit);
$auditReport = file_get_contents($root.'/docs/release-audit.md');

if (! is_string($auditReport) || $auditReport !== ReleaseAudit::render($audit)) {
    $errors[] = 'The generated pre-release audit report is stale.';
}

$beta = BetaEvidence::load($root.'/beta/evidence.json');

foreach (BetaEvidence::releaseGateErrors($beta) as $error) {
    $errors[] = "Beta evidence: {$error}";
}

$betaReport = file_get_contents($root.'/docs/beta-evidence.md');

if (! is_string($betaReport) || $betaReport !== BetaEvidence::render($beta)) {
    $errors[] = 'The generated beta evidence report is stale.';
}

if ($errors !== []) {
    fwrite(STDERR, "Stable release gate is blocked:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Stable release gate is backed by published upgrade and beta evidence.\n");
