<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Ratio;

// A fuzzy score ranks a shortlist. It does not decide which record is the same
// entity. This file walks that line: ranking first, then the pair a score
// cannot separate, then a rule that can.
$query = 'Acme Corp';
$records = [
    'Acme Corporation',
    'Acme Construction',
    'Acme Corp.',
    'Acme Corp International',
    'Beta Corp',
    'Acme & Sons Ltd',
];

function rank(array $records, string $query, ?Ratio $kind = null): array
{
    $scored = [];
    foreach ($records as $record) {
        $scored[$record] = $kind === null
            ? Fuzz::ratio($query, $record)
            : Fuzz::ratio($query, $record, $kind);
    }
    arsort($scored);

    return $scored;
}

echo "Ratio::Standard:\n";
foreach (rank($records, $query) as $record => $score) {
    printf("  %.3f  %s\n", $score, $record);
}

// TokenSet scores a record 1.0 when every query word appears in it, so a longer
// name outranks the exact match. That is the subset rule working as designed,
// and it is also why the top of this list is not the answer.
echo "\nRatio::TokenSet:\n";
foreach (rank($records, $query, Ratio::TokenSet) as $record => $score) {
    printf("  %.3f  %s\n", $score, $record);
}

// Both names below start with "Acme Corp" and are one word longer than the
// query. A character-level score sees the same edit in each. Five of the ten
// cases rank the non-match higher and two more tie it at 1.0.
$match = ['Acme Corporation', 'Acme Corp'];
$nonMatch = ['Acme Corporation', 'Acme Construction'];
echo "\nThe pair a score cannot separate:\n";
foreach (Ratio::cases() as $kind) {
    $yes = Fuzz::ratio($match[0], $match[1], $kind);
    $no = Fuzz::ratio($nonMatch[0], $nonMatch[1], $kind);
    printf(
        "  %-18s match %.3f   non-match %.3f%s\n",
        $kind->name,
        $yes,
        $no,
        $yes > $no ? '' : '   <- wrong order',
    );
}

// Decide with a rule. Expanding the abbreviations makes the two sides directly
// comparable, and exact comparison is what a threshold cannot give you.
const ABBREVIATIONS = ['corp' => 'corporation', 'co' => 'company', 'ltd' => 'limited'];

function canonical(string $name): string
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_map(static fn (string $word) => ABBREVIATIONS[$word] ?? $word, $words);
    sort($words);

    return implode(' ', $words);
}

echo "\nSame record after abbreviation expansion, compared exactly:\n";
foreach ($records as $record) {
    $verdict = canonical($query) === canonical($record) ? 'same' : 'different';
    printf("  %-24s %s\n", $record, $verdict);
}

// Sample output. Floats are one Apple Silicon run.
//
// Ratio::Standard:
//   0.947  Acme Corp.
//   0.720  Acme Corporation
//   0.667  Beta Corp
//   0.615  Acme Construction
//   0.562  Acme Corp International
//   0.500  Acme & Sons Ltd
//
// Ratio::TokenSet:
//   1.000  Acme Corp International
//   0.947  Acme Corp.
//   0.720  Acme Corporation
//   0.667  Beta Corp
//   0.615  Acme Construction
//   0.615  Acme & Sons Ltd
//
// The pair a score cannot separate:
//   Standard           match 0.720   non-match 0.727   <- wrong order
//   Partial            match 1.000   non-match 0.688
//   TokenSort          match 0.720   non-match 0.727   <- wrong order
//   TokenSet           match 0.720   non-match 0.727   <- wrong order
//   PartialTokenSort   match 1.000   non-match 0.688
//   PartialTokenSet    match 1.000   non-match 1.000   <- wrong order
//   Token              match 0.720   non-match 0.727   <- wrong order
//   PartialToken       match 1.000   non-match 1.000   <- wrong order
//   Weighted           match 0.900   non-match 0.727
//   Quick              match 0.720   non-match 0.727   <- wrong order
//
// Same record after abbreviation expansion, compared exactly:
//   Acme Corporation         same
//   Acme Construction        different
//   Acme Corp.               same
//   Acme Corp International  different
//   Beta Corp                different
//   Acme & Sons Ltd          different
