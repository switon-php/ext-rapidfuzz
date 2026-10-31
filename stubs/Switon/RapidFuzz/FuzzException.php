<?php

declare(strict_types=1);

namespace Switon\RapidFuzz;

/**
 * Base type for every failure the extension raises.
 *
 * A `catch (FuzzException $e)` covers an invalid string, a library failure, and
 * a Hamming call on unequal lengths. {@see Utf8Exception} narrows the first.
 *
 * IDE stub. The extension provides this class at runtime. Do not include this file.
 */
class FuzzException extends \Exception
{
}
