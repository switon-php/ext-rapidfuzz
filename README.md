# ext-rapidfuzz

[![CI](https://github.com/switon-php/ext-rapidfuzz/actions/workflows/ci.yml/badge.svg)](https://github.com/switon-php/ext-rapidfuzz/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHP extension for fuzzy string scores. Two strings go in, one number comes back: how close they are when they are not an exact match.

Exact equality misses `张三` against `张山`, and it misses `New York Jets` against `new york jet`. A fuzzy score measures that gap. This extension computes the score; it does not store a list of candidates and it does not pick a winner. The caller already holds the pair, or walks a short list in PHP and keeps the best score.

```php
use Switon\RapidFuzz\Distance;
use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Ratio;

Fuzz::ratio('New York Jets', 'new york jet');  // 0.72
Fuzz::ratio('张三 李四', '李四 张三', Ratio::TokenSort);  // 1.0
Fuzz::jaroWinkler('martha', 'marhta');  // 0.961
Distance::levenshtein('kitten', 'sitting');  // 3
```

## Requirements

- PHP 8.2 to 8.5, NTS or ZTS.
- macOS Apple Silicon, Linux glibc on `x86_64` and `arm64`, or Windows x64.
- A C++17 toolchain, only for a source build.

## Install

```bash
pie install switon/rapidfuzz
```

PIE takes a prebuilt file from the GitHub release when the PHP version matches, and compiles the sources otherwise. A source build needs the toolchain in [Build](#build).

## API

`Fuzz` returns a similarity on **0 to 1**, where 1 means equal. `Distance` returns an edit count, where 0 means equal. Every method takes `$left` and `$right` and is static. Both constructors are protected.

### `Fuzz`: similarity, higher is closer

| Method | Extra arguments | Use it for |
| --- | --- | --- |
| `ratio` | `Ratio $kind`, `float $scoreCutoff` | the default score for a whole pair |
| `jaro` | `float $scoreCutoff` | short strings, character-level overlap |
| `jaroWinkler` | `float $prefixWeight`, `float $scoreCutoff` | short strings where a shared prefix should count |

`Ratio` selects what `ratio` compares:

| Case | Score |
| --- | --- |
| `Standard` | normalized indel over the whole string; the default |
| `Partial` | best window of the shorter string inside the longer one |
| `TokenSort` | words sorted first, then the whole string |
| `TokenSet` | shared words against the words unique to each side |
| `PartialTokenSort` | partial ratio on the sorted words |
| `PartialTokenSet` | partial ratio on the shared words |
| `Token` | the better of `TokenSort` and `TokenSet` |
| `PartialToken` | the better of `PartialTokenSort` and `PartialTokenSet` |
| `Weighted` | FuzzyWuzzy's weighted mix of the family |
| `Quick` | the whole string, except two empty strings score 0 |

### `Distance`: edit count, lower is closer

| Method | Extra arguments | Counts |
| --- | --- | --- |
| `levenshtein` | `$insertionCost`, `$replacementCost`, `$deletionCost`, `$scoreCutoff`, `$scoreHint` | insertions, deletions, substitutions |
| `damerauLevenshtein` | `$scoreCutoff` | the same, plus transposing two neighbours |
| `osa` | `$scoreCutoff` | the same, one transposition as one edit |
| `hamming` | `bool $pad`, `$scoreCutoff` | substitutions only, equal-length strings |
| `indel` | `$scoreCutoff` | insertions and deletions only |
| `lcs` | `$scoreCutoff` | lengths minus twice the longest common subsequence |
| `prefix` | `$scoreCutoff` | code points after the common prefix |
| `postfix` | `$scoreCutoff` | code points before the common suffix |

Signatures, defaults, and per-method notes are in [stubs/README.md](stubs/README.md). Call-style samples are in [examples/](examples/).

## Where a service uses it

| Situation | Call |
| --- | --- |
| A name or an address | `ratio` over the whole string |
| Short strings that share a prefix | `jaroWinkler`, for example `martha` against `marhta` |
| The same words in another order | `ratio` with `Ratio::TokenSort` |
| One string that repeats a word | `ratio` with `Ratio::TokenSet` |
| A short string inside a longer one | `ratio` with `Ratio::Partial` |
| A rule of "at most N edits" | `Distance::levenshtein` |

That table answers which call to make, not which record is the same entity. The next two sections are about the second question.

## A score ranks, it does not decide

A score orders candidates. It does not answer "is this the same entity". The two questions look alike and are not.

One pair a directory has to get right:

| Pair | Should match | `Ratio::Standard` |
| --- | --- | --- |
| `Acme Corporation` / `Acme Corp` | yes | 0.720 |
| `Acme Corporation` / `Acme Construction` | no | **0.727** |

The non-match scores higher. Five of the ten `Ratio` cases rank it strictly higher — `Standard`, `TokenSort`, `TokenSet`, `Token`, and `Quick` — and two more tie it at 1.0, `PartialTokenSet` and `PartialToken`. `Partial` orders this pair correctly, and still scores `New York Jets` against `New York Giants` at 0.818, above the 0.750 it gives `New York Jets` against `new york jet`.

This is not a defect in the library. `Corporation` against `Corp` and `Corporation` against `Construction` are the same edit at the character level, so no character-level metric can tell them apart. The information is not in the strings.

`Ratio::TokenSet` has a second edge. It scores 1.0 whenever every query word appears in the record, so the longer name wins:

```text
1.000  Acme Corp International
0.947  Acme Corp.
0.720  Acme Corporation
```

That is the subset rule doing what it documents, and it is also why "keep everything above 0.9" returns the wrong first row. [examples/rank.php](examples/rank.php) prints that list and the ten-way comparison.

Normalization and token ratios fix differences in *form*: case, accents, word order, punctuation. They do not fix differences in *meaning*, and meaning is what separates the two `Acme` records.

## Candidate generation, then ranking

Three stages, and the score belongs to the middle one.

1. **Block.** Pull a cheap subset out of the store: a folded prefix, a shared token, a phonetic key, a trigram index. The extension is not involved here, and this is where recall comes from.
2. **Rank.** Score the shortlist and sort it. This is `Fuzz::ratio`.
3. **Decide.** Check the top rows against a rule, a second model, or a person.

```php
// 1. Block: the first four code points after folding.
$shortlist = array_filter($records, fn (string $r) => blockKey($r) === blockKey($query));

// 2. Rank.
$scored = [];
foreach ($shortlist as $record) {
    $scored[$record] = Fuzz::ratio($query, $record, Ratio::TokenSet);
}
arsort($scored);

// 3. Decide: a rule, not a threshold.
foreach (array_slice($scored, 0, 5, true) as $record => $score) {
    if (canonical($query) === canonical($record)) {
        return $record;
    }
}
```

`canonical()` expands known abbreviations and sorts the words, so the comparison at the end is exact. [examples/rank.php](examples/rank.php) has the full version.

Stage 2 is not the expensive part. A thousand candidates of twenty characters take about 0.25 ms, which is less than the database round trip that produced the list. What stage 2 cannot do is stage 3's job, and no `Ratio` case changes that.

## Scores are 0 to 1

`Fuzz::ratio` returns `0.96`, not `96`. The whole `Fuzz` surface shares one scale, so a threshold of `0.8` means the same thing for `ratio`, `jaro`, and `jaroWinkler`. The C++ library reports its `fuzz` family on 0 to 100; the extension scales at the boundary so the PHP side never sees it. Multiply by 100 only at the display edge.

`$scoreCutoff` turns a scan into a filter. A score below the cutoff comes back as `0.0`, so the caller compares against zero instead of the threshold:

```php
$best = 0.0;
foreach ($candidates as $candidate) {
    $score = Fuzz::ratio($needle, $candidate, scoreCutoff: $best);
    if ($score > $best) {
        $best = $score;
    }
}
```

That loop is a convenience, not a speedup. Measured on `Ratio::Standard`, a cutoff buys about 1.5× on strings of 300 to 1000 characters and nothing measurable below that, because the score is already bit-parallel. The pruning pays off on `Distance`, where a cutoff of 5 on 1000-character strings is about 9× faster than computing the exact count. On `Distance` the cutoff also runs the other way: the count is exact when it is at or below the cutoff, and above it the call returns the cutoff plus one, which is not the real count.

## Normalization

Both strings are decoded as UTF-8 and compared as Unicode code points, so `张三` and `张山` differ by one character and the Levenshtein distance is 1. The same pair compared as raw bytes differs in three.

There is no case-folding and no Unicode normalization. `É` and `é` stay different, and a precomposed `é` is not the same sequence as `e` plus a combining accent. Fold case, accents, and punctuation in PHP first:

| Pair | Raw | After folding |
| --- | --- | --- |
| `New York Jets` / `new york jet` | 0.72 | 0.96 |
| `Café` / `cafe` | 0.50 | 1.00 |
| `ＡＢＣ` / `abc` | 0.00 | 1.00 |

[examples/normalize.php](examples/normalize.php) has the guarded `normalize()` behind that table, and [stubs/README.md](stubs/README.md#normalization) explains when folding is the wrong move.

## Why not the built-in

PHP ships four functions that compare strings fuzzily or phonetically, and not one of them compares characters.

| Built-in | What it does | Where it breaks |
| --- | --- | --- |
| `levenshtein()` | edit count | counts bytes, so one CJK character is three edits |
| `similar_text()` | matching characters, optionally a percentage | counts bytes, so a CJK character carries three times the weight of a Latin letter |
| `soundex()` | phonetic key | Latin letters only; `张三` gives `0000` |
| `metaphone()` | phonetic key | Latin letters only; `张三` gives an empty string |

Measured on PHP 8.3:

| Pair | True difference | `levenshtein()` | `Distance::levenshtein()` |
| --- | --- | --- | --- |
| `张三` / `张山` | 1 character | 3 | 1 |
| 90 CJK characters, one replaced | 1 character | 3 | 1 |

`similar_text()` is not just wrong, it is inconsistent. Replacing one CJK character and replacing one Latin letter are the same edit, but the byte count splits them into two very different scores:

```php
similar_text('张三ab', '张山ab', $cjkPct);    // 62.50%
similar_text('张三ab', '张三ac', $latinPct);  // 87.50%
```

Past correctness, PHP has no token-based score, no Jaro or Jaro-Winkler, no OSA or unrestricted Damerau-Levenshtein, and no threshold that bounds the work. `mb_str_split()` plus a hand-written DP loop does give a correct answer, at about 40× the cost of this extension on CJK names and with an array allocated on every call.

## Compared with PHP

The same indel ratio in PHP is a dynamic-programming loop. The same Levenshtein distance is another loop. PHP's `levenshtein()` is already C, so a short pair is not a disaster; the cost is the O(n·m) table, which reaches 371 ms at 20000 bytes against 0.4 ms here. `bench/bench.php` checks that the two sides agree, then times them after a warmup. The inputs are ASCII, so a byte and a code point are the same unit.

One local run, PHP 8.3.30 NTS on Apple Silicon:

| Call | PHP | rapidfuzz |
| --- | --- | --- |
| ratio, 32 bytes | 77.80 µs | 0.33 µs |
| ratio, 256 bytes | 4716.41 µs | 3.95 µs |
| levenshtein, 32 bytes | 77.13 µs | 0.40 µs |
| levenshtein, 256 bytes | 5030.08 µs | 5.57 µs |

That is about 240× on the 32-byte ratio and about 1200× on the 256-byte ratio. The 32-byte Levenshtein distance is about 195×, and the 256-byte one is about 900×. It is one machine, not a portability claim. A single short pair in a request does not need the extension; the gain shows up when the call sits on a hot path, or when the strings grow long enough for the table to hurt.

`make bench` repeats that run. It is separate from `make test` and is not part of CI.

## Long-running workers

A call keeps its decoded code points only for that call, so nothing carries between requests. Swoole, RoadRunner, and FrankenPHP get the same score on request 10000 as on the first. The calls take no lock under ZTS, and a coroutine switch cannot land in the middle of one, because the extension makes no I/O and never yields.

Two things to know: the decode buffer is allocated outside the Zend allocator, so it is not counted against `memory_limit`; and a call is CPU-bound, so a `levenshtein` on megabyte strings holds the event loop. Keep the inputs bounded.

## Scope

It is not a search engine, a spell-checker dictionary, or a nearest-neighbour index. A million-row scan still belongs in the database. It scores a pair and ranks a shortlist; it does not rank a corpus, and it does not decide identity.

[rapidfuzz-cpp](https://github.com/rapidfuzz/rapidfuzz-cpp) 3.3.4 is the C++ library behind the Python package [RapidFuzz](https://github.com/rapidfuzz/RapidFuzz). This extension compiles that tag's headers into the module.

## Build

The library matches the `php` and `php-config` on `PATH` while it compiles. Those two commands must print the same version. On Windows there is no `php-config`; the build reads the PHP on `PATH`. The result loads only on that PHP minor version, that NTS or ZTS build, and that CPU. The extension is C++17.

`make test` is the root `Makefile`. It runs `phpize` inside `pie/`, so the root file is not replaced. `pie/config.m4` is the Unix source build. `pie/config.w32` is the Windows source build. A `pie/config.m4` newer than `pie/configure` reruns `phpize`. `./configure` runs again when `config.m4` or the `php-config` on `PATH` changes, and a leftover `conftest` directory is removed first. The generated `Makefile` has to contain `-std=c++17` and the RapidFuzz include path, or the build stops.

From a clone of this repository:

```bash
make test
```

`make test` builds the library and loads it. The file is `pie/modules/rapidfuzz.so` on macOS and Linux, and `pie/modules/php_rapidfuzz.dll` on Windows. `php -m` lists the module as `rapidfuzz`, and the classes stay under `Switon\RapidFuzz`.

```bash
php -d extension="$PWD/pie/modules/rapidfuzz.so" -m
```

On Windows use `php_rapidfuzz.dll`.

### Homebrew

Apple Silicon. The Command Line Tools supply clang, which is the C++17 compiler this build uses.

```bash
xcode-select --install
brew install php
export PATH="$(brew --prefix php)/bin:$PATH"
php -v
php-config --version
make test
```

### apt

Debian and Ubuntu. `php-dev` follows the distribution's default PHP, which must be 8.2–8.5. `build-essential` supplies `g++`.

```bash
sudo apt update
sudo apt install build-essential php-cli php-dev
php -v
php-config --version
make test
```

To compile against a specific minor version, install that pair instead, for example `php8.3-cli` and `php8.3-dev`, and put that `php` and `php-config` first on `PATH`.

### yum

RHEL, AlmaLinux, Rocky Linux, and Fedora. On Fedora the same packages install with `dnf`. `php-devel` must be PHP 8.2–8.5. `gcc-c++` supplies the C++17 compiler.

```bash
sudo yum install gcc-c++ make php-cli php-devel
php -v
php-config --version
make test
```

A ZTS library is a second build. Point `PATH` and `PHP_CONFIG` at the ZTS `php` and `php-config`, then run `make test` again. On Windows, put the ZTS `php.exe` first on `PATH` instead.

### Windows

Windows x64. PHP is a [windows.php.net](https://windows.php.net/) build, VS16 for 8.2 and 8.3, VS17 for 8.4 and 8.5. The Visual Studio developer prompt supplies `cl.exe` and `nmake`, and that toolset has to be the one the PHP binary was linked with. CI uses MSVC 14.29 for 8.2 and 8.3, and 14.44 for 8.4 and 8.5. A newer 14.51 `link.exe` produces a DLL that PHP refuses to load. The matching PHP development pack supplies `phpize` and `php.exe`. `configure` also requires `bison` and `re2c`; both are in the PHP SDK binary tools, under `msys2/usr/bin`. `PHP_PREFIX` is that PHP directory. Run `make` from Git Bash so it can see `uname`.

```bash
export PHP_PREFIX=C:/php
php -v
make test
```

`make test` runs `phpize`, `configure`, and `nmake` inside `pie/`, then copies `php_rapidfuzz.dll` to `pie/modules/`.

### Loading a release asset

The extension version is the git tag without the leading `v`. A tag `v1.0.1` builds version `1.0.1`, and `php --ri rapidfuzz` reports that. A commit after that tag keeps the distance, such as `1.0.1-3`, and does not include the commit id. With no tag in the clone, the build uses `1.0.0`.

A release asset is `rapidfuzz-1.0.1-php8.3-nts-linux-x64.tar.gz`: that version, the PHP minor, `nts` or `zts`, `linux`, `macos`, or `windows`, `x64` or `arm64`. Unpack it. The only file inside is `rapidfuzz.so` on Linux and macOS, or `php_rapidfuzz.dll` on Windows.

```ini
extension=/path/rapidfuzz.so
```

`extension=rapidfuzz` looks for `rapidfuzz.so` on Linux and macOS, and for `php_rapidfuzz.dll` on Windows. On Windows the line is `extension=C:/path/php_rapidfuzz.dll`. The file still has to match the PHP minor version, NTS or ZTS, and the CPU. A Linux build from Ubuntu 24.04 needs that glibc or a newer one.

## CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs `make test` on every push and pull request: PHP 8.5 NTS on each runner.

A `v*` tag runs [`.github/workflows/release.yml`](.github/workflows/release.yml). That build is the full set: PHP 8.2–8.5, NTS and ZTS, on all four runners. Each archive contains one library, and CI rejects a build that links a second project library. `pie install switon/rapidfuzz` uses the `php_rapidfuzz-*.zip` assets from that release.

## License

MIT. See [LICENSE](LICENSE).
