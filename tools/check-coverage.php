<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments) || count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php <clover.xml> <minimum-percent>\n");

    exit(2);
}

$path = $arguments[1] ?? null;
$threshold = $arguments[2] ?? null;

if (! is_string($path) || ! is_string($threshold)) {
    fwrite(STDERR, "The coverage path and a minimum between 0 and 100 are required.\n");

    exit(2);
}

$minimum = filter_var($threshold, FILTER_VALIDATE_FLOAT);

if ($minimum === false || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "The coverage path and a minimum between 0 and 100 are required.\n");

    exit(2);
}

$document = new DOMDocument;

if (! is_file($path) || ! @$document->load($path)) {
    fwrite(STDERR, "Unable to read Clover coverage report [{$path}].\n");

    exit(2);
}

$xpath = new DOMXPath($document);
$matches = $xpath->query('/coverage/project/metrics');
$metrics = $matches === false ? null : $matches->item(0);

if (! $metrics instanceof DOMElement) {
    fwrite(STDERR, "Clover report does not contain project statement metrics.\n");

    exit(2);
}

$statements = $metrics->getAttribute('statements');
$covered = $metrics->getAttribute('coveredstatements');

if ($statements === '' || $covered === '' || ! ctype_digit($statements) || ! ctype_digit($covered)) {
    fwrite(STDERR, "Clover report contains invalid project statement metrics.\n");

    exit(2);
}

$statementCount = (int) $statements;
$coveredCount = (int) $covered;

if ($statementCount === 0 || $coveredCount > $statementCount) {
    fwrite(STDERR, "Clover report contains invalid project statement metrics.\n");

    exit(2);
}

$percentage = ($coveredCount / $statementCount) * 100;

printf(
    "Line coverage: %.2f%% (%d/%d executable lines); required: %.2f%%.\n",
    $percentage,
    $coveredCount,
    $statementCount,
    $minimum,
);

exit($percentage + 0.00001 >= $minimum ? 0 : 1);
