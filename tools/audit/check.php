<?php

declare(strict_types=1);

use RaceProof\AuditTools\ReleaseAudit;

require_once __DIR__.'/ReleaseAudit.php';

$root = dirname(__DIR__, 2);
$audit = ReleaseAudit::load($root.'/audit/release-audit.json');
$errors = ReleaseAudit::validationErrors($root, $audit);

if ($errors !== []) {
    fwrite(STDERR, "Release audit failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

$expectedReport = ReleaseAudit::render($audit);
$actualReport = file_get_contents($root.'/docs/release-audit.md');

if (! is_string($actualReport) || $actualReport !== $expectedReport) {
    fwrite(STDERR, "docs/release-audit.md is stale. Run `composer release:audit-report`.\n");
    exit(1);
}

$directory = $root.'/build/release-audit';

if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}.\n");
    exit(1);
}

$evidence = json_encode(
    ReleaseAudit::machineEvidence($audit),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n";

if (file_put_contents($directory.'/evidence.json', $evidence, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write release-audit evidence.\n");
    exit(1);
}

$blockedIssues = ReleaseAudit::machineEvidence($audit)['blocked_issues'];
$blocked = implode(', #', $blockedIssues);
fwrite(STDOUT, "Release audit controls pass; external evidence and the stable workflow outcome remain blocked in issues #{$blocked}.\n");
