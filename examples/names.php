<?php

declare(strict_types=1);

use Switon\RapidFuzz\Distance;
use Switon\RapidFuzz\Fuzz;

// One character differs. PHP's built-in counts bytes, so it sees three edits.
echo 'built-in levenshtein():  ', levenshtein('张三', '张山'), "\n";

// The extension compares code points, so the same pair is one edit.
echo 'Distance::levenshtein(): ', Distance::levenshtein('张三', '张山'), "\n";
echo 'Fuzz::ratio():           ', Fuzz::ratio('张三', '张山'), "\n";
echo 'Fuzz::jaroWinkler():     ', Fuzz::jaroWinkler('张三', '张山'), "\n";

// Sample output:
// built-in levenshtein():  3
// Distance::levenshtein(): 1
// Fuzz::ratio():           0.5
// Fuzz::jaroWinkler():     0.66666666666667
