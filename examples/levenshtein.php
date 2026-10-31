<?php

declare(strict_types=1);

use Switon\RapidFuzz\Distance;

echo Distance::levenshtein('kitten', 'sitting'), "\n";

// Sample output:
// 3
