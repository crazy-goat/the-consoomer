<?php

declare(strict_types=1);

/**
 * CI coverage floor (#242).
 *
 * PHPUnit has no built-in minimum-coverage gate, so this parses a Clover
 * report and fails when line coverage is below the required percentage.
 *
 * Usage: php scripts/coverage-gate.php <clover.xml> <minimum-line-percentage>
 * Exit codes: 0 = pass, 1 = below minimum, 2 = usage/report error.
 */
$clover = $argv[1] ?? null;
$minimum = isset($argv[2]) ? (float) $argv[2] : null;

if ($clover === null || $minimum === null || !is_file($clover)) {
    fwrite(\STDERR, "Usage: php scripts/coverage-gate.php <clover.xml> <minimum-line-percentage>\n");
    exit(2);
}

$xml = simplexml_load_file($clover);
if ($xml === false) {
    fwrite(\STDERR, sprintf('Cannot parse coverage report "%s".' . "\n", $clover));
    exit(2);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(\STDERR, "Coverage report has no <project><metrics> element.\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percentage = $statements > 0 ? 100 * $covered / $statements : 0.0;

printf(
    "Line coverage: %.2f%% (%d/%d statements), minimum %.2f%%\n",
    $percentage,
    $covered,
    $statements,
    $minimum,
);

if ($percentage + 1e-9 < $minimum) {
    fwrite(\STDERR, sprintf("Line coverage %.2f%% is below the required minimum of %.2f%%.\n", $percentage, $minimum));
    exit(1);
}

echo "Coverage gate passed.\n";
