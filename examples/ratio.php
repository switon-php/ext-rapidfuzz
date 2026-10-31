<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Ratio;

echo Fuzz::ratio('this is a test', 'this is a test!'), "\n";
echo Fuzz::ratio('this is a test', 'this is a test!', Ratio::Partial), "\n";

// Sample output. Both are on the 0-to-1 scale.
// 0.96551724137931
// 1
