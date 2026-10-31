<?php

declare(strict_types=1);

use Switon\RapidFuzz\Distance;
use Switon\RapidFuzz\Fuzz;

if (!extension_loaded('rapidfuzz')) {
    fwrite(STDERR, "rapidfuzz extension is not loaded\n");
    exit(1);
}

/**
 * @param callable(): float|int $fn
 */
function microseconds(int $iterations, callable $fn): float
{
    for ($i = 0; $i < 20; $i++) {
        $fn();
    }
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }

    return (hrtime(true) - $start) / $iterations / 1000;
}

function text(int $length, int $seed): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz ';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[($i * 17 + $seed) % 27];
    }

    return $out;
}

function phpLevenshtein(string $left, string $right): int
{
    $n = strlen($left);
    $m = strlen($right);
    $previous = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $current = [$i];
        $leftChar = $left[$i - 1];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $leftChar === $right[$j - 1] ? 0 : 1;
            $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
        }
        $previous = $current;
    }

    return $previous[$m];
}

/** Indel distance: a substitution is one deletion plus one insertion. */
function phpIndel(string $left, string $right): int
{
    $n = strlen($left);
    $m = strlen($right);
    $previous = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $current = [$i];
        $leftChar = $left[$i - 1];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $leftChar === $right[$j - 1] ? 0 : 2;
            $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
        }
        $previous = $current;
    }

    return $previous[$m];
}

function phpRatio(string $left, string $right): float
{
    $total = strlen($left) + strlen($right);
    if ($total === 0) {
        return 1.0;
    }

    /* The extension reports every similarity on 0 to 1, so the reference
       implementation does too. Keeping both sides on one scale is what makes
       the agreement check below meaningful. */
    return 1.0 - phpIndel($left, $right) / $total;
}

$shortLeft = text(32, 1);
$shortRight = text(32, 4);
$longLeft = text(256, 1);
$longRight = text(256, 4);

$checks = [
    ['ratio 32', phpRatio($shortLeft, $shortRight), Fuzz::ratio($shortLeft, $shortRight), 1e-6],
    ['ratio 256', phpRatio($longLeft, $longRight), Fuzz::ratio($longLeft, $longRight), 1e-6],
    ['levenshtein 32', (float) phpLevenshtein($shortLeft, $shortRight), (float) Distance::levenshtein($shortLeft, $shortRight), 0.0],
    ['levenshtein 256', (float) phpLevenshtein($longLeft, $longRight), (float) Distance::levenshtein($longLeft, $longRight), 0.0],
];
foreach ($checks as [$name, $php, $ext, $tolerance]) {
    if (abs($php - $ext) > $tolerance) {
        fwrite(STDERR, "$name diverged: php $php ext $ext\n");
        exit(1);
    }
}

$rows = [
    ['ratio 32', 5000, static fn (): float => phpRatio($shortLeft, $shortRight), static fn (): float => Fuzz::ratio($shortLeft, $shortRight)],
    ['ratio 256', 200, static fn (): float => phpRatio($longLeft, $longRight), static fn (): float => Fuzz::ratio($longLeft, $longRight)],
    ['levenshtein 32', 5000, static fn (): float => (float) phpLevenshtein($shortLeft, $shortRight), static fn (): float => (float) Distance::levenshtein($shortLeft, $shortRight)],
    ['levenshtein 256', 200, static fn (): float => (float) phpLevenshtein($longLeft, $longRight), static fn (): float => (float) Distance::levenshtein($longLeft, $longRight)],
];

printf("%-16s %10s %10s\n", 'call', 'php', 'rapidfuzz');
foreach ($rows as [$name, $iterations, $php, $ext]) {
    printf(
        "%-16s %8.2f us %8.2f us\n",
        $name,
        microseconds($iterations, $php),
        microseconds($iterations, $ext),
    );
}
