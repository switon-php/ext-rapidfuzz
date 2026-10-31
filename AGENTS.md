# AGENTS.md

`README.md` has the build steps. `stubs/README.md` has the method list. `examples/` shows the call style. Review that style before adding a public method.

## Language

PHPDoc, both READMEs, `phpinfo()` strings, and commit messages are English.

## Source

The scoring code is RapidFuzz 3.3.4 in `third_party/rapidfuzz/`. The headers live under `include/` and are compiled into `src/php_rapidfuzz.cpp`. Do not take a newer tag. Do not put a file named `version` on that include path: libc++ includes `<version>`.

## API

`Fuzz` and `Distance` methods are static. Both strings are decoded as UTF-8 and compared as code points. Every similarity is on 0 to 1, so `Fuzz::ratio` and `Fuzz::jaro` share a scale; `Ratio` selects the variant. `Distance` returns edit counts. A score below `$scoreCutoff` comes back as 0.0. An edit count above it comes back as the cutoff plus one. The library's `fuzz` family reports 0 to 100, so `rapidfuzz_score_percent()` scales the cutoff in and the score out; keep that conversion in that one helper.

## Bench

`make bench` times `Fuzz` against a PHP loop on the same inputs. It is not part of `make test` or CI. The README table is one machine, replaced when this command is run again.

## Tests

`tests/fuzz.php` checks every public method, invalid UTF-8, phpinfo, and the protected constructor. A candidate-list loop stays in PHP and is not part of the extension.

`tests/001-fuzz.phpt` requires that script, so the phpize target runs the same checks instead of reporting that no tests ran. The name matters: run-tests writes its temp file beside the `.phpt`, so a file named `fuzz.phpt` would overwrite `fuzz.php`. `pie/config.m4` links `tests/` into `pie/` for this.

`make cover` rebuilds the extension with source coverage and requires at least 95% line coverage of `src/php_rapidfuzz.cpp`. It uses llvm-cov. Out-of-memory, an edit count that does not fit a PHP int, a missing `Ratio` case at startup, and an unsupported `Ratio` case cannot be reached from PHP. Those lines are marked `LCOV_EXCL` and stay out of the percentage. Unequal Hamming lengths with padding off throw the library message and are covered by `tests/fuzz.php`.

## Distribution

Packagist and PIE. Copy `composer.json` and the asset names in `.github/actions/test/action.yml`. The source build is `pie/config.m4` and `pie/config.w32`. `make clean` runs `phpize --clean` inside `pie/` and removes the autoconf leftovers and the `php-config` stamp. A later `make test` configures again only when `config.m4` or that `php-config` changed. Do not add PECL, Homebrew, or distro packages.
