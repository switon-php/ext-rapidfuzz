<?php

declare(strict_types=1);

use Switon\RapidFuzz\Fuzz;

// A cutoff turns a score into a filter. A pair below the threshold comes back
// as 0.0, so the loop skips it without comparing against the threshold itself.
$needle = '张伟';
$candidates = ['张伟', '张伟明', '李伟', '王伟', '张'];
$threshold = 0.6;

foreach ($candidates as $candidate) {
    $score = Fuzz::ratio($needle, $candidate, scoreCutoff: $threshold);
    if ($score > 0.0) {
        echo $candidate, ' ', $score, "\n";
    }
}

// Sample output. The threshold is on the same 0-to-1 scale as the score.
// 张伟 1
// 张伟明 0.8
// 张 0.66666666666667
