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

$path = $root.'/docs/release-audit.md';

if (file_put_contents($path, ReleaseAudit::render($audit), LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write {$path}.\n");
    exit(1);
}

fwrite(STDOUT, "Updated docs/release-audit.md.\n");
