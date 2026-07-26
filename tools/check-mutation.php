<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments) || count($arguments) !== 3) {
    fwrite(STDERR, "Usage: php tools/check-mutation.php <report.txt> <minimum-percent>\n");

    exit(2);
}

$path = $arguments[1] ?? null;
$threshold = $arguments[2] ?? null;

if (! is_string($path) || ! is_string($threshold)) {
    fwrite(STDERR, "The mutation report path and a minimum between 0 and 100 are required.\n");

    exit(2);
}

$minimum = filter_var($threshold, FILTER_VALIDATE_FLOAT);

if ($minimum === false || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "The mutation report path and a minimum between 0 and 100 are required.\n");

    exit(2);
}

$report = is_file($path) ? file_get_contents($path) : false;

if (! is_string($report)) {
    fwrite(STDERR, "Unable to read mutation report [{$path}].\n");

    exit(2);
}

$plainReport = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $report);

if (! is_string($plainReport)
    || preg_match(
        '/^\s*Mutations:\s*(\d+\s+(?:untested|timeout|tested)(?:,\s*\d+\s+(?:untested|timeout|tested))*)\s*$/im',
        $plainReport,
        $matches,
    ) !== 1
) {
    fwrite(STDERR, "Mutation report does not contain the expected mutation totals.\n");

    exit(2);
}

$totals = [
    'untested' => 0,
    'timeout' => 0,
    'tested' => 0,
];
$seen = [];

if (preg_match_all(
    '/(\d+)\s+(untested|timeout|tested)/i',
    $matches[1],
    $categoryMatches,
    PREG_SET_ORDER,
) === false) {
    fwrite(STDERR, "Mutation report does not contain the expected mutation totals.\n");

    exit(2);
}

foreach ($categoryMatches as $categoryMatch) {
    $category = strtolower($categoryMatch[2]);

    if (isset($seen[$category])) {
        fwrite(STDERR, "Mutation report contains duplicate mutation totals.\n");

        exit(2);
    }

    $seen[$category] = true;
    $totals[$category] = (int) $categoryMatch[1];
}

$untested = $totals['untested'];
$timeouts = $totals['timeout'];
$tested = $totals['tested'];
$total = $untested + $timeouts + $tested;

if ($total === 0) {
    fwrite(STDERR, "Mutation report contains no mutations.\n");

    exit(2);
}

$percentage = ($tested / $total) * 100;

printf(
    "Strict mutation score: %.2f%% (%d/%d tested; %d untested; %d timeout); required: %.2f%%.\n",
    $percentage,
    $tested,
    $total,
    $untested,
    $timeouts,
    $minimum,
);

exit($percentage + 0.00001 >= $minimum ? 0 : 1);
