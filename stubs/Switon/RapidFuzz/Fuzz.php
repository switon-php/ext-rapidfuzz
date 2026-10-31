<?php

declare(strict_types=1);

namespace Switon\RapidFuzz;

/**
 * Fuzzy string scores from RapidFuzz 3.3.4.
 *
 * Every call returns a similarity from 0 to 1: 1 means equal, and a higher
 * number means the strings are closer. {@see Distance} holds the edit counts,
 * where a lower number means closer.
 *
 * Each call decodes both strings as UTF-8 and compares Unicode code points.
 * A byte-level distance is not available. No Unicode normalization is applied,
 * so the caller passes both strings in the same normalization form. Invalid
 * UTF-8 raises {@see Utf8Exception}.
 *
 * {@see Ratio} selects the whole string, the best partial window, or a
 * whitespace-token comparison. The default is {@see Ratio::Standard}. Token
 * scores split on the library's whitespace set, which includes U+00A0 and U+3000.
 *
 * IDE stub. The extension provides this class at runtime. Do not include this file.
 * The constructor is protected. Score calls are static.
 */
class Fuzz
{
    protected function __construct()
    {
    }

    /**
     * Normalized indel similarity, from 0 to 1.
     *
     * A substitution counts as one deletion and one insertion.
     * {@see Ratio::Partial} scores the shorter string against windows of the longer one.
     * {@see Ratio::TokenSort} sorts the whitespace-separated words first.
     * {@see Ratio::TokenSet} compares the shared and unique words.
     *
     * Two empty strings are the one input where the cases disagree: 1.0 under
     * `Standard`, `Partial`, `TokenSort`, `PartialTokenSort`, `Token`, and
     * `PartialToken`, but 0.0 under `TokenSet`, `PartialTokenSet`, `Weighted`, and
     * `Quick`.
     *
     * `$scoreCutoff` bounds the work on the same 0-to-1 scale. Zero means no
     * cutoff. Above zero, a score below the cutoff comes back as 0.0, which lets
     * a candidate loop compare against zero instead of the threshold.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function ratio(string $left, string $right, Ratio $kind = Ratio::Standard, float $scoreCutoff = 0.0): float
    {
    }

    /**
     * Jaro similarity, from 0 to 1. `$scoreCutoff` works as it does in {@see ratio()}.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function jaro(string $left, string $right, float $scoreCutoff = 0.0): float
    {
    }

    /**
     * Jaro-Winkler similarity, from 0 to 1.
     *
     * At most four leading code points count, and the prefix bonus applies only
     * when the Jaro score is above 0.7. `$prefixWeight` scales that bonus and is
     * 0.1 by default. `$scoreCutoff` works as it does in {@see ratio()}.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function jaroWinkler(string $left, string $right, float $prefixWeight = 0.1, float $scoreCutoff = 0.0): float
    {
    }
}
