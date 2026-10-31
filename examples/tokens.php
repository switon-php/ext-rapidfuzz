<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Ratio;

$left = 'fuzzy was a bear';
$right = 'fuzzy fuzzy was a bear';

echo Fuzz::ratio($left, $right, Ratio::TokenSort), "\n";
echo Fuzz::ratio($left, $right, Ratio::TokenSet), "\n";

// Sample output:
// 0.84210526315789
// 1
