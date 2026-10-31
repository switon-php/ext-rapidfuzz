<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;

echo Fuzz::jaroWinkler('martha', 'marhta'), "\n";

// Sample output. The scale is 0 to 1.
// 0.96111111111111
