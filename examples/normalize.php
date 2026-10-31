<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Ratio;

// The extension compares code points exactly, so case, punctuation, accents,
// and full-width forms decide most real matches before the score is computed.
// intl and mbstring are both optional; the guards keep this working without them.
function normalize(string $text): string
{
    if (class_exists('Normalizer')) {
        $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
    }
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    if (function_exists('mb_convert_kana')) {
        $text = mb_convert_kana($text, 'as', 'UTF-8');
    }
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

$pairs = [
    ['New York Jets', 'new york jet'],
    ['Café', 'cafe'],
    ['ＡＢＣ', 'abc'],
    ['张三 李四', '李四，张三'],
];

foreach ($pairs as [$left, $right]) {
    $normalizedLeft = normalize($left);
    $normalizedRight = normalize($right);
    printf(
        "%s | %s -> %.2f, normalized -> %.2f, token sort -> %.2f\n",
        $left,
        $right,
        Fuzz::ratio($left, $right),
        Fuzz::ratio($normalizedLeft, $normalizedRight),
        Fuzz::ratio($normalizedLeft, $normalizedRight, Ratio::TokenSort),
    );
}

// Sample output. The middle column is Ratio::Standard after normalize().
// The last pair stays at 0.40 there: normalization does not reorder words.
// New York Jets | new york jet -> 0.72, normalized -> 0.96, token sort -> 0.96
// Café | cafe -> 0.50, normalized -> 1.00, token sort -> 1.00
// ＡＢＣ | abc -> 0.00, normalized -> 1.00, token sort -> 1.00
// 张三 李四 | 李四，张三 -> 0.40, normalized -> 0.40, token sort -> 1.00
