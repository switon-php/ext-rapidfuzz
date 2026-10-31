<?php

declare(strict_types=1);

namespace Switon\RapidFuzz;

/**
 * Edit counts from RapidFuzz 3.3.4.
 *
 * Every call returns a count: a lower number means the strings are closer, and
 * 0 means they are equal. {@see Fuzz} holds the similarities, where a higher
 * number means closer.
 *
 * Each call decodes both strings as UTF-8 and compares Unicode code points.
 * No Unicode normalization is applied. Invalid UTF-8 raises {@see Utf8Exception}.
 *
 * `$scoreCutoff` bounds the work and defaults to `PHP_INT_MAX`, which means no
 * cutoff. The result is exact when it is at or below the cutoff. Above it the
 * call returns the cutoff plus one, which is not the real count: the caller
 * learns the distance is at least that large without paying for the exact value.
 *
 * IDE stub. The extension provides this class at runtime. Do not include this file.
 * The constructor is protected. Count calls are static.
 */
class Distance
{
    protected function __construct()
    {
    }

    /**
     * Levenshtein edit count.
     *
     * One substitution counts as one edit. The three costs match the built-in
     * `levenshtein()`, and a negative cost is treated as 0. `$scoreHint` is the
     * library's search hint and defaults to `PHP_INT_MAX`. It does not change an
     * exact result.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function levenshtein(
        string $left,
        string $right,
        int $insertionCost = 1,
        int $replacementCost = 1,
        int $deletionCost = 1,
        int $scoreCutoff = PHP_INT_MAX,
        int $scoreHint = PHP_INT_MAX,
    ): int {
    }

    /**
     * Damerau-Levenshtein edit count. One adjacent transposition counts as one edit.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function damerauLevenshtein(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Hamming distance. `$pad` lets the shorter string match the longer one.
     *
     * Without padding, unequal lengths raise {@see FuzzException} with the library message.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function hamming(string $left, string $right, bool $pad = true, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Indel edit count. One substitution counts as one deletion and one insertion.
     *
     * This is the count behind {@see Fuzz::ratio()}: `1 - indel / (len left + len right)`.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function indel(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Lengths minus twice the longest common subsequence.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function lcs(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Optimal string alignment. One adjacent transposition counts as one edit,
     * and a substring is not edited twice.
     *
     * This is the count to use for a typo that swaps two neighbouring characters.
     * `teh` against `the` is 1 here and 2 under {@see levenshtein()}.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function osa(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Code points after the common prefix.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function prefix(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }

    /**
     * Code points before the common suffix.
     *
     * @throws Utf8Exception when either string is not valid UTF-8
     * @throws FuzzException when the library call fails
     */
    public static function postfix(string $left, string $right, int $scoreCutoff = PHP_INT_MAX): int
    {
    }
}
