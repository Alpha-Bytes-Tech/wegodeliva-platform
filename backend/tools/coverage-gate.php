<?php

/*
 * Fails when any statement in the given paths is not covered (CLAUDE.md §4: 100% coverage of
 * pricing, payment, wallet and order-state logic). Usage:
 *   php tools/coverage-gate.php build/clover.xml app/Services/Pricing app/Support/Money.php
 */

[$script, $clover] = $argv + [null, null];
$paths = array_slice($argv, 2);
if ($clover === null || $paths === [] || ! is_file($clover)) {
    fwrite(STDERR, "Usage: php {$script} <clover.xml> <path> [<path> ...]\n");
    exit(2);
}

$root = realpath(__DIR__.'/..').'/';
$xml = simplexml_load_file($clover);
$checked = 0;
$uncovered = [];

foreach ($xml->xpath('//file') ?: [] as $file) {
    $relative = str_replace($root, '', (string) $file['name']);
    $inScope = array_filter($paths, fn (string $p) => $relative === $p || str_starts_with($relative, rtrim($p, '/').'/'));
    if ($inScope === []) {
        continue;
    }
    $checked++;
    foreach ($file->line as $line) {
        if ((int) $line['count'] === 0 && in_array((string) $line['type'], ['stmt', 'method'], true)) {
            $uncovered[] = "{$relative}:{$line['num']}";
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "No files matched; check the paths.\n");
    exit(1);
}
if ($uncovered !== []) {
    fwrite(STDERR, "Not covered by tests:\n  ".implode("\n  ", $uncovered)."\n");
    exit(1);
}

echo "Full coverage in {$checked} files.\n";
