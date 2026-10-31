# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). A version is the git tag without the leading `v`, which is the string `php --ri rapidfuzz` reports.

## [Unreleased]

## [1.0.0]

First release.

- `Fuzz::ratio` takes a `Ratio` case and a `$scoreCutoff`. The enum has ten cases: `Standard`, `Partial`, `TokenSort`, `TokenSet`, `PartialTokenSort`, `PartialTokenSet`, `Token`, `PartialToken`, `Weighted`, and `Quick`.
- `Fuzz::jaro` and `Fuzz::jaroWinkler`, both with a `$scoreCutoff`. `jaroWinkler` also takes `$prefixWeight`, which defaults to 0.1.
- `Distance::levenshtein`, `damerauLevenshtein`, `hamming`, `indel`, `lcs`, `osa`, `prefix`, and `postfix`, each with a `$scoreCutoff`.
- `levenshtein` takes `$insertionCost`, `$replacementCost`, and `$deletionCost` in the order PHP's `levenshtein()` uses, plus a `$scoreHint`. `hamming` takes `$pad`.
- `Fuzz` returns similarities on 0 to 1, where 1 is equal and higher is closer. `Distance` returns edit counts, where 0 is equal and lower is closer.
- Every method takes `$left` and `$right`, so named arguments work.
- Both strings are decoded as UTF-8 and compared as code points. Invalid UTF-8 raises `Utf8Exception`, and the message names the argument.
- A score below `$scoreCutoff` comes back as `0.0`. A distance above it comes back as `$scoreCutoff + 1`.
- `FuzzException` is the base type. `Utf8Exception` extends it.
- macOS Apple Silicon, Linux glibc on `x86_64` and `arm64`, and Windows x64. PHP 8.2 to 8.5, NTS and ZTS.
