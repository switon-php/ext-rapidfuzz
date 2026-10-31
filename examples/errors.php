<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;
use Switon\RapidFuzz\Utf8Exception;

try {
    Fuzz::ratio("\xFF", 'a');
    echo "no exception\n";
} catch (Utf8Exception $e) {
    echo $e->getMessage(), "\n";
}

// Sample output. The message names the argument that failed.
// Argument #1 ($left) must be valid UTF-8
