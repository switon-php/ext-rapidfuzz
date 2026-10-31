# Switon\RapidFuzz

IDE stubs for the switon RapidFuzz extension. One type per file. The directory matches the namespace. Do not `include` these files and do not Composer-autoload them. The extension provides the same classes at runtime.

`Fuzz` and `Distance` wrap RapidFuzz 3.3.4. Both strings are decoded as UTF-8 and compared as code points. Call-style samples are in [examples/](../examples/).

`Fuzz` returns similarities on 0 to 1: 1 means equal, and a higher number means the strings are closer. `Distance` returns edit counts: a lower number means closer, and 0 means equal. The two scales differ on purpose, because a count is not a ratio.

`Ratio` selects the whole string, the best partial window, or a whitespace-token comparison. The default is `Ratio::Standard`, the normalized indel score: a substitution is one deletion plus one insertion, so it is not `1 - levenshtein / max(len)`.

Every parameter is named `$left` and `$right`, so named arguments read as `Fuzz::ratio(left: $a, right: $b)`.

## Recommended use

Compare two names or two short texts the caller already holds. A loop over a candidate list stays in PHP.

```php
use Switon\RapidFuzz\Fuzz;

$score = Fuzz::ratio($left, $right);
```

Rank, then decide. A score orders candidates; it does not establish that two records are the same entity. The two questions look alike and are not.

| Pair | Should match | `Ratio::Standard` |
| --- | --- | --- |
| `Acme Corporation` / `Acme Corp` | yes | 0.720 |
| `Acme Corporation` / `Acme Construction` | no | **0.727** |

Five of the ten cases rank the non-match strictly higher, and two more tie it at 1.0. `Corporation` against `Corp` and `Corporation` against `Construction` are the same edit at the character level, so the distinction is not in the strings. `Ratio::TokenSet` adds its own edge: it scores 1.0 whenever every query word appears in the record, so a longer name outranks the exact one.

Keep the score for the shortlist and compare the top rows against a rule. [../examples/rank.php](../examples/rank.php) is the worked version.

## Normalization

The extension compares code points exactly. Case, punctuation, accents, and full-width forms decide most real matches before a score is computed, and no `Ratio` case fixes them. `New York Jets` against `new york jet` scores 0.72 under all ten, `Ratio::Weighted` included. Lowercased first it scores 0.96.

Do it in PHP before the call. `intl` and `mbstring` are both optional, so guard each.

```php
function normalize(string $text): string
{
    if (class_exists('Normalizer')) {
        $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
    }
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    }
    if (function_exists('mb_convert_kana')) {
        $text = mb_convert_kana($text, 'as', 'UTF-8');
    }
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}
```

What it buys, measured with `Ratio::Standard`:

| Pair | Raw | Normalized |
| --- | --- | --- |
| `New York Jets` / `new york jet` | 0.72 | 0.96 |
| `Café` / `cafe` | 0.50 | 1.00 |
| `ＡＢＣ` / `abc` | 0.00 | 1.00 |
| `张三 李四` / `李四，张三` | 0.40 | 0.40 |

The last pair stays at 0.40 because normalization does not reorder words. `Ratio::TokenSort` on the same normalized strings scores 1.00. [examples/normalize.php](../examples/normalize.php) prints all three: the raw score, `Ratio::Standard` after normalization, and `Ratio::TokenSort` after normalization.

Normalization is not always right. A code, a hash, or an identifier where case carries meaning should be compared as given.

## Classes

| Class | Role |
| --- | --- |
| `Switon\RapidFuzz\Fuzz` | Similarity calls, 0 to 1. The constructor is protected. |
| `Switon\RapidFuzz\Distance` | Edit-count calls. The constructor is protected. |
| `Switon\RapidFuzz\Ratio` | Which score `ratio` computes. A backed string enum. |
| `Switon\RapidFuzz\FuzzException` | Base type for every failure the extension raises. |
| `Switon\RapidFuzz\Utf8Exception` | A string is not valid UTF-8. Extends `FuzzException`. |

A value that is not a string, or a `kind` that is not a `Ratio` case, is a PHP `TypeError`, raised by the engine before the call. No exception type reports a bad `kind`.

## Methods

`Fuzz`, similarity on 0 to 1, higher is closer:

| PHP | RapidFuzz |
| --- | --- |
| `ratio` with `Ratio::Standard` | `rapidfuzz::fuzz::ratio` |
| `ratio` with `Ratio::Partial` | `rapidfuzz::fuzz::partial_ratio` |
| `ratio` with `Ratio::TokenSort` | `rapidfuzz::fuzz::token_sort_ratio` |
| `ratio` with `Ratio::TokenSet` | `rapidfuzz::fuzz::token_set_ratio` |
| `ratio` with `Ratio::PartialTokenSort` | `rapidfuzz::fuzz::partial_token_sort_ratio` |
| `ratio` with `Ratio::PartialTokenSet` | `rapidfuzz::fuzz::partial_token_set_ratio` |
| `ratio` with `Ratio::Token` | `rapidfuzz::fuzz::token_ratio` |
| `ratio` with `Ratio::PartialToken` | `rapidfuzz::fuzz::partial_token_ratio` |
| `ratio` with `Ratio::Weighted` | `rapidfuzz::fuzz::WRatio` |
| `ratio` with `Ratio::Quick` | `rapidfuzz::fuzz::QRatio` |
| `jaro` | `rapidfuzz::jaro_similarity` |
| `jaroWinkler` | `rapidfuzz::jaro_winkler_similarity` |

`Distance`, edit count, lower is closer:

| PHP | RapidFuzz |
| --- | --- |
| `levenshtein` | `rapidfuzz::levenshtein_distance` |
| `damerauLevenshtein` | `rapidfuzz::experimental::damerau_levenshtein_distance` |
| `hamming` | `rapidfuzz::hamming_distance` |
| `indel` | `rapidfuzz::indel_distance` |
| `lcs` | `rapidfuzz::lcs_seq_distance` |
| `osa` | `rapidfuzz::osa_distance` |
| `prefix` | `rapidfuzz::prefix_distance` |
| `postfix` | `rapidfuzz::postfix_distance` |

The library reports the `fuzz` family on 0 to 100. The extension divides by 100, and scales a `$scoreCutoff` by 100 before passing it down, so the whole PHP surface stays on 0 to 1.

`indel` is the count behind `Fuzz::ratio`: `1 - indel / (len left + len right)`.

`osa` counts one adjacent transposition as one edit, so `teh` against `the` is 1 there and 2 under `levenshtein`. `damerauLevenshtein` is the unrestricted form of the same idea, and the library keeps it under `experimental`.

`jaroWinkler` counts at most four leading code points, and it applies the prefix bonus only when the Jaro score is above 0.7. `$prefixWeight` scales that bonus and is 0.1 by default.

`levenshtein` takes the same three costs as the built-in `levenshtein()`, in the same order: insertion, replacement, deletion. A negative cost is treated as 0.

## Cutoff

`$scoreCutoff` is a filter first and an optimisation second. It lets a loop skip a score it would discard, without a comparison against the threshold.

`ratio`, `jaro`, and `jaroWinkler` default to 0.0, which means no cutoff. Above zero, a score below the cutoff comes back as 0.0.

The speedup is modest on `Fuzz`: the score is bit-parallel already, so a cutoff buys roughly 1.5× at 300 to 1000 characters and nothing measurable below that. It is real on `Distance`, where the cutoff prunes the search: a cutoff of 5 against 1000-character strings measured about 9× faster than the exact count.

`levenshtein` and the other edit counts default to `PHP_INT_MAX`, which means no cutoff. The result is exact when it is at or below the cutoff. Above it the library returns the cutoff plus one, not the real distance. A negative cutoff is treated as 0. `levenshtein` also takes `$scoreHint`, the library's search hint; the default does not change an exact result.

`Ratio::Weighted`, `Ratio::Quick`, `Ratio::TokenSet`, and `Ratio::PartialTokenSet` score two empty strings as 0; the other six score 1.0. `hamming` without padding raises `FuzzException` with the library message when the lengths differ.

```php
$best = 0.0;
foreach ($candidates as $candidate) {
    $score = Fuzz::ratio($needle, $candidate, scoreCutoff: $best);
    if ($score > $best) {
        $best = $score;
    }
}
```

## Against the built-in

PHP's own similarity functions all measure bytes, not characters. Measured on PHP 8.3:

| Pair | True difference | `levenshtein()` | `Distance::levenshtein()` |
| --- | --- | --- | --- |
| `张三` / `张山` | 1 character | 3 | 1 |
| 90 CJK characters, one replaced | 1 character | 3 | 1 |

`similar_text()` is byte-weighted rather than merely byte-counted, so the same edit scores differently depending on the script: `张三ab` against `张山ab` is 62.50%, while `张三ab` against `张三ac` is 87.50%. One character differs in both. `soundex('张三')` is `0000` and `metaphone('张三')` is an empty string.

PHP 8 removed the 255-byte limit on `levenshtein()`, so length is no longer the reason to avoid it; the O(n·m) table is. At 20000 bytes the built-in takes 371 ms and this extension 0.4 ms, for the same answer.

What PHP has no equivalent for at all: a token-based score, Jaro, Jaro-Winkler, OSA, unrestricted Damerau-Levenshtein, and any threshold that bounds the work. `mb_str_split()` plus a hand-written loop produces a correct answer at roughly 40× the cost, with an array allocated per call.

## Pitfalls

The comparison unit is a Unicode code point. `张三` and `张山` have Levenshtein distance 1. A byte-level comparison of the UTF-8 form reports 3.

No Unicode normalization is applied. A precomposed `é` (U+00E9) and a decomposed `e` plus U+0301 are different code point sequences: Levenshtein distance 2 and `ratio` 0. Pass both strings in the same normalization form.

Two empty strings are the one input where the cases disagree. Six score 1.0: `Standard`, `Partial`, `TokenSort`, `PartialTokenSort`, `Token`, and `PartialToken`. Four score 0: `TokenSet` and `PartialTokenSet` because there is no shared token to score, and `Weighted` and `Quick` because the library reports an empty pair as a miss. Every case scores one empty string against a non-empty one as 0.

A score is not a percentage. `Fuzz::ratio` returns 0.96, not 96. Multiply at the display edge, and keep thresholds on the same scale as the score.

`Ratio::TokenSort` and `Ratio::TokenSet` split on the library's whitespace set: U+0009 to U+000D, U+001C to U+001F, U+0020, U+0085, U+00A0, U+1680, U+2000 to U+200A, U+2028, U+2029, U+202F, U+205F, and U+3000. U+200B is not in the set. They do not case-fold and they do not strip punctuation.

Under ZTS the calls need no lock. Each call keeps its decoded code points only for that call.

A long-running worker keeps nothing between requests either. Swoole, RoadRunner, and FrankenPHP get the same score on request 10000 as on the first, and a coroutine switch cannot land in the middle of a call, because the extension makes no I/O and never yields.

The decode buffer is not counted against `memory_limit`. Each call allocates up to four bytes per input byte outside the Zend allocator, so a pair of 100 MB strings needs roughly 800 MB of headroom on top of the limit.

A call is CPU-bound and blocks the worker for its whole duration. At short lengths that is about a microsecond. A `levenshtein` on strings in the megabyte range holds the event loop, so keep the inputs bounded or move the call off the coroutine.

## Static analysis

PHPStan and Psalm load stub files by path, not by directory. Point `stubFiles` or `scanFiles` at each of the five files above. The paths are relative to the project being analysed, so they depend on where this repository is checked out.
