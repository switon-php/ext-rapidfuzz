# Examples

Call-style samples for `Switon\RapidFuzz`. Read them next to `stubs/`. They are not tests.

Each file is one everyday use: indel ratio, token scores, Jaro-Winkler, an edit count, names compared as code points, a cutoff over a short list, case and punctuation folded in PHP, ranking a shortlist before a rule decides, and invalid UTF-8. The sample output sits under the call in each file. Integer lines are exact. Float lines are one Apple Silicon run.

```bash
php -d extension="$PWD/pie/modules/rapidfuzz.so" examples/ratio.php
```

On Windows the file is `pie/modules/php_rapidfuzz.dll`.

| File | Shows |
| --- | --- |
| `ratio.php` | Whole-string score, and the partial window |
| `tokens.php` | Token sort and token set on reordered words |
| `jaro.php` | Jaro-Winkler on a pair that shares a prefix |
| `levenshtein.php` | The edit count |
| `names.php` | Code-point comparison on CJK names |
| `threshold.php` | A cutoff as a filter over a candidate list |
| `normalize.php` | Case, accents, and punctuation folded before the score |
| `rank.php` | Ranking a shortlist, and the rule that decides after it |
| `errors.php` | `Utf8Exception` and its message |
