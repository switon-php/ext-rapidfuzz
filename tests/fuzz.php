<?php

declare(strict_types=1);

use Switon\RapidFuzz\Distance;
use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\FuzzException;
use Switon\RapidFuzz\Ratio;
use Switon\RapidFuzz\Utf8Exception;

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function same(mixed $got, mixed $want, string $message): void
{
    if ($got !== $want) {
        fail($message . ': got ' . var_export($got, true) . ', want ' . var_export($want, true));
    }
}

function near(float $got, float $want, string $message): void
{
    if (abs($got - $want) > 1e-9) {
        fail($message . ': got ' . var_export($got, true) . ', want ' . var_export($want, true));
    }
}

same(Distance::levenshtein('kitten', 'sitting'), 3, 'levenshtein of kitten and sitting');
same(Distance::levenshtein('张三', '张山'), 1, 'levenshtein counts code points, not bytes');
same(Distance::levenshtein('', ''), 0, 'levenshtein of two empty strings');
same(Distance::levenshtein('', 'ab'), 2, 'levenshtein of empty and ab');
same(Distance::levenshtein('a', 'b'), 1, 'levenshtein of two single characters');
same(Distance::levenshtein("a\0b", 'ab'), 1, 'levenshtein accepts a NUL byte');

near(Fuzz::ratio('same', 'same'), 1.0, 'ratio of identical strings');
near(Fuzz::ratio('same', 'same', Ratio::Standard), 1.0, 'ratio with an explicit standard kind');
near(Fuzz::ratio('', ''), 1.0, 'ratio of two empty strings');
near(Fuzz::ratio('', 'a'), 0.0, 'ratio of empty and a');
near(Fuzz::ratio('this is a test', 'this is a test!', Ratio::Partial), 1.0, 'partial ratio of a contained string');
near(Fuzz::ratio('abcd', 'abXcd', Ratio::Partial), 0.75, 'partial ratio of abcd and abXcd');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::TokenSet), 1.0, 'token set ratio of a contained string');
near(Fuzz::ratio('fuzzy was a bear', 'wuzzy was a bear', Ratio::TokenSet), 0.9375, 'token set ratio');
near(Fuzz::ratio('this is a test', 'this is a test!'), 0.9655172413793103, 'ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::TokenSort), 0.8421052631578947, 'token sort ratio');
near(Fuzz::ratio('张三', '张山'), 0.5, 'ratio of two names');
near(Fuzz::ratio('张三', '张山丰', Ratio::Partial), 0.6666666666666667, 'partial ratio of two names');
near(Fuzz::ratio('张三', '姓名张三完', Ratio::Partial), 1.0, 'partial ratio of a contained name');
near(Fuzz::ratio('张三 李四', '李四 张三', Ratio::TokenSort), 1.0, 'token sort ratio of reordered names');
near(Fuzz::ratio('张三 李四', '张三 王五', Ratio::TokenSort), 0.6, 'token sort ratio of partly shared names');
near(Fuzz::ratio('张三 李四', '张三 王五', Ratio::TokenSet), 0.6, 'token set ratio of partly shared names');
near(Fuzz::ratio("张三\u{3000}李四", '李四 张三', Ratio::TokenSort), 1.0, 'ideographic space separates words');
near(Fuzz::ratio("a\u{00A0}b", 'b a', Ratio::TokenSort), 1.0, 'no-break space separates words');
near(Fuzz::ratio("a\u{200B}b", 'b a', Ratio::TokenSort), 0.6666666666666667, 'zero-width space does not separate words');
near(Fuzz::ratio('😀', '😀'), 1.0, 'ratio of identical emoji');
near(Fuzz::ratio('😀', '😁'), 0.0, 'ratio of two different emoji');
near(Fuzz::ratio("\u{00E9}", "e\u{0301}"), 0.0, 'ratio does not normalize');
same(Distance::levenshtein("\u{00E9}", "e\u{0301}"), 2, 'levenshtein does not normalize');

near(Fuzz::ratio('', '', Ratio::TokenSort), 1.0, 'token sort ratio of two empty strings');
near(Fuzz::ratio('', '', Ratio::TokenSet), 0.0, 'token set ratio of two empty strings');
near(Fuzz::ratio('', 'a', Ratio::TokenSet), 0.0, 'token set ratio of empty and a');

near(Fuzz::jaroWinkler('same', 'same'), 1.0, 'jaro winkler of identical strings');
near(Fuzz::jaroWinkler('', 'a'), 0.0, 'jaro winkler of empty and a');
near(Fuzz::jaroWinkler('', ''), 1.0, 'jaro winkler of two empty strings');
near(Fuzz::jaroWinkler('martha', 'marhta'), 0.9611111111111111, 'jaro winkler of martha and marhta');
near(Fuzz::jaroWinkler('张三', '张山'), 0.6666666666666666, 'jaro winkler of two names');
near(Fuzz::jaroWinkler('😀', '😀'), 1.0, 'jaro winkler of identical emoji');
near(Fuzz::jaroWinkler('abcdefgh', 'abcdefgX'), 0.95, 'jaro winkler caps the prefix at four code points');

// Every Fuzz method reports the same 0-to-1 scale, so an exact match is 1.0
// whatever the method. This is the assertion that catches a scale regression.
same(Fuzz::ratio('x', 'x'), 1.0, 'ratio reports 1.0 for an exact match');
same(Fuzz::jaro('x', 'x'), 1.0, 'jaro reports 1.0 for an exact match');
same(Fuzz::jaroWinkler('x', 'x'), 1.0, 'jaro winkler reports 1.0 for an exact match');

// A cutoff bounds the work. A worse score comes back as 0.0. An edit count
// above the cutoff comes back as the cutoff plus one, which is not the real distance.
near(Fuzz::ratio('kitten', 'sitting', scoreCutoff: 0.9), 0.0, 'a cutoff above the ratio returns 0');
near(Fuzz::ratio('kitten', 'sitting', scoreCutoff: 0.5), 0.6153846153846154, 'a cutoff below the ratio keeps it');
near(Fuzz::ratio('kitten', 'sitting', scoreCutoff: 0.6153846153846154), 0.6153846153846154, 'a cutoff equal to the ratio keeps it');
near(Fuzz::ratio('abcd', 'abXcd', Ratio::Partial, 0.99), 0.0, 'partial ratio honours the cutoff');
near(Fuzz::ratio('abcd', 'abXcd', Ratio::Partial, 0.7), 0.75, 'partial ratio keeps a score above the cutoff');

near(Fuzz::jaroWinkler('martha', 'marhta', 0.25), 0.9861111111111112, 'jaro winkler takes a prefix weight');
near(Fuzz::jaroWinkler('martha', 'marhta', scoreCutoff: 0.99), 0.0, 'jaro winkler honours the cutoff');
near(Fuzz::jaroWinkler('martha', 'marhta', 0.1, 0.9), 0.9611111111111111, 'jaro winkler keeps a score above the cutoff');

same(Distance::levenshtein('kitten', 'sitting', scoreCutoff: 1), 2, 'a cutoff below the distance returns cutoff plus one');
same(Distance::levenshtein('kitten', 'sitting', scoreCutoff: 3), 3, 'a cutoff at the distance is exact');
same(Distance::levenshtein('kitten', 'sitting', scoreCutoff: 10), 3, 'a cutoff above the distance is exact');
same(Distance::levenshtein('kitten', 'sitting', scoreHint: 3), 3, 'a score hint does not change an exact distance');
same(Distance::levenshtein('lewenstein', 'levenshtein', replacementCost: 2), 3, 'a heavier replacement cost raises the count');
same(Distance::levenshtein('kitten', 'sitting', insertionCost: -5), 2, 'a negative cost is treated as zero');
same(Distance::levenshtein('kitten', 'sitting', scoreCutoff: -5), 1, 'a negative cutoff is treated as zero');

near(Fuzz::ratio(left: 'abcd', right: 'abXcd', kind: Ratio::Partial), 0.75, 'ratio accepts named arguments');

same(Ratio::Standard->value, 'ratio', 'standard backing value');
same(Ratio::Partial->value, 'partial', 'partial backing value');
same(Ratio::TokenSort->value, 'tokenSort', 'token sort backing value');
same(Ratio::TokenSet->value, 'tokenSet', 'token set backing value');
same(Ratio::PartialTokenSort->value, 'partialTokenSort', 'partial token sort backing value');
same(Ratio::PartialTokenSet->value, 'partialTokenSet', 'partial token set backing value');
same(Ratio::Token->value, 'token', 'token backing value');
same(Ratio::PartialToken->value, 'partialToken', 'partial token backing value');
same(Ratio::Weighted->value, 'weighted', 'weighted backing value');
same(Ratio::Quick->value, 'quick', 'quick backing value');
same(count(Ratio::cases()), 10, 'ratio case count');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::PartialTokenSort), 0.8965517241379311, 'partial token sort ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::PartialTokenSet), 1.0, 'partial token set ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::Token), 1.0, 'token ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::PartialToken), 1.0, 'partial token ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::Weighted), 0.95, 'weighted ratio');
near(Fuzz::ratio('fuzzy was a bear', 'fuzzy fuzzy was a bear', Ratio::Quick), 0.8421052631578947, 'quick ratio');
near(Fuzz::ratio('', '', Ratio::Weighted), 0.0, 'weighted ratio of two empty strings');
near(Fuzz::ratio('', '', Ratio::Quick), 0.0, 'quick ratio of two empty strings');
near(Fuzz::jaro('martha', 'marhta'), 0.9444444444444445, 'jaro of martha and marhta');
near(Fuzz::jaro('martha', 'marhta', 0.99), 0.0, 'jaro honours the cutoff');
same(Distance::damerauLevenshtein('ca', 'ac'), 1, 'damerau counts one transposition');
same(Distance::hamming('karolin', 'kathrin'), 3, 'hamming of karolin and kathrin');
same(Distance::hamming('abc', 'a'), 2, 'hamming pads the shorter string');
same(Distance::indel('kitten', 'sitting'), 5, 'indel counts a substitution as two edits');
same(Distance::lcs('kitten', 'sitting'), 3, 'lcs distance of kitten and sitting');
same(Distance::osa('ca', 'ac'), 1, 'osa counts one adjacent transposition');
same(Distance::prefix('abc', 'abd'), 1, 'prefix distance of abc and abd');
same(Distance::postfix('abc', 'xbc'), 1, 'postfix distance of abc and xbc');
same(Distance::indel('kitten', 'sitting', scoreCutoff: 2), 3, 'every edit count returns cutoff plus one, not only levenshtein');
same(Distance::indel('kitten', 'sitting', scoreCutoff: 5), 5, 'a cutoff at the distance is exact for every edit count');
same(Distance::hamming('abc', 'a', true, 1), 2, 'pad comes before the cutoff in hamming');
try {
    Distance::hamming('ab', 'a', false);
    fail('hamming accepted unequal lengths without padding');
} catch (FuzzException $e) {
    same($e->getMessage(), 'Sequences are not the same length.', 'hamming reports the library message');
}
same(Ratio::from('partial'), Ratio::Partial, 'ratio from a backing value');
same(Ratio::tryFrom('nope'), null, 'ratio try from an unknown backing value');

try {
    Fuzz::ratio('a', 'b', 'partial');
    fail('ratio accepted a string kind');
} catch (TypeError) {
}

try {
    Fuzz::ratio('a', 'b', new stdClass());
    fail('ratio accepted a foreign object kind');
} catch (TypeError) {
}

try {
    Fuzz::ratio(1, 'b');
    fail('ratio accepted an int');
} catch (TypeError) {
}

try {
    Fuzz::jaroWinkler('a', null);
    fail('jaro winkler accepted null');
} catch (TypeError) {
}

function expectUtf8(callable $call, string $argument, string $message): void
{
    try {
        $call();
        fail($message);
    } catch (Utf8Exception $e) {
        same($e->getMessage(), $argument . ' must be valid UTF-8', $message);
    }
}

expectUtf8(static fn () => Fuzz::ratio("\xFF", 'a'), 'Argument #1 ($left)', 'invalid utf-8 was accepted');
expectUtf8(static fn () => Fuzz::ratio('a', "\xFF"), 'Argument #2 ($right)', 'invalid utf-8 on the right was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xC2", 'a', Ratio::Partial), 'Argument #1 ($left)', 'truncated utf-8 was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xC2\x20", 'a'), 'Argument #1 ($left)', 'a bad continuation byte was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xE0\x80\x80", 'a'), 'Argument #1 ($left)', 'a 3-byte overlong sequence was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xF0\x80\x80\x80", 'a'), 'Argument #1 ($left)', 'a 4-byte overlong sequence was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xED\xA0\x80", 'a', Ratio::TokenSort), 'Argument #1 ($left)', 'surrogate was accepted');
expectUtf8(static fn () => Fuzz::ratio("\xF4\x90\x80\x80", 'a', Ratio::TokenSet), 'Argument #1 ($left)', 'code point above U+10FFFF was accepted');
expectUtf8(static fn () => Fuzz::jaroWinkler("\xE4\xB8", '张'), 'Argument #1 ($left)', 'truncated utf-8 on jaro was accepted');
expectUtf8(static fn () => Distance::levenshtein("\xC0\x80", 'a'), 'Argument #1 ($left)', 'overlong utf-8 was accepted');
expectUtf8(static fn () => Distance::levenshtein('a', "\xC0\x80"), 'Argument #2 ($right)', 'overlong utf-8 on the right was accepted');

/* These seven share one decode helper, so each has to reach it with bad input
   on both sides. A method that skipped the helper would return a number and
   pass a check that only looked at one of them. */
foreach (['damerauLevenshtein', 'hamming', 'indel', 'lcs', 'osa', 'prefix', 'postfix'] as $method) {
    expectUtf8(static fn () => Distance::$method("\xC0\x80", 'a'), 'Argument #1 ($left)', $method . ' accepted overlong utf-8');
    expectUtf8(static fn () => Distance::$method('a', "\xC0\x80"), 'Argument #2 ($right)', $method . ' accepted overlong utf-8 on the right');
}

foreach ([Fuzz::class, Distance::class] as $type) {
    try {
        (new ReflectionClass($type))->newInstance();
        fail($type . ' constructor is public');
    } catch (ReflectionException) {
    }

    $constructed = (new ReflectionClass($type))->newInstanceWithoutConstructor();
    (new ReflectionMethod($type, '__construct'))->invoke($constructed);
}

ob_start();
phpinfo(INFO_MODULES);
$info = (string) ob_get_clean();
if (!str_contains($info, 'rapidfuzz') || !str_contains($info, '3.3.4')) {
    fail('phpinfo did not report the module');
}

// One base type covers every failure, and Utf8Exception narrows the decode one.
if (!is_a(Utf8Exception::class, FuzzException::class, true)) {
    fail('Utf8Exception does not extend FuzzException');
}
if (!is_a(FuzzException::class, \Exception::class, true)) {
    fail('FuzzException does not extend the SPL Exception');
}
if (class_exists('Switon\\RapidFuzz\\OverflowException')) {
    fail('OverflowException is still registered');
}
// Class lookup ignores case, so a lowercase probe cannot tell the two spellings
// apart. Reflection reports the name as registered, which is what docs quote.
same(
    (new ReflectionClass('switon\\rapidfuzz\\fuzz'))->getName(),
    'Switon\\RapidFuzz\\Fuzz',
    'the registered namespace spelling',
);

// The stub is what IDEs and static analysis read. A parameter renamed on one
// side and not the other silently breaks named arguments, so the names are
// compared rather than trusted.
foreach (['Fuzz' => 3, 'Distance' => 8] as $class => $expected) {
    $fqcn = 'Switon\\RapidFuzz\\' . $class;
    $stub = file_get_contents(__DIR__ . '/../stubs/Switon/RapidFuzz/' . $class . '.php');
    preg_match_all('/public static function (\w+)\(([^)]*)\)/', $stub, $declarations, PREG_SET_ORDER);
    same(count($declarations), $expected, 'stub declares every method of ' . $class);
    foreach ($declarations as [, $name, $parameters]) {
        preg_match_all('/\$(\w+)/', $parameters, $found);
        $fromStub = $found[1];
        $fromRuntime = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod($fqcn, $name))->getParameters(),
        );
        same($fromRuntime, $fromStub, 'stub parameter names for ' . $class . '::' . $name);
    }
}

echo "ok\n";
