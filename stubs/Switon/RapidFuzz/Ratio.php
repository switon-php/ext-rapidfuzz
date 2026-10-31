<?php

declare(strict_types=1);

namespace Switon\RapidFuzz;

/**
 * Which score {@see Fuzz::ratio()} computes. Every case returns 0 to 1.
 *
 * A backed string enum. Each backing value is the RapidFuzz selector name, so
 * `Ratio::from('partial')` is `Ratio::Partial`.
 *
 * IDE stub. The extension provides this enum at runtime. Do not include this file.
 */
enum Ratio: string
{
    /** Normalized indel similarity of the whole strings. */
    case Standard = 'ratio';

    /** Best indel similarity of the shorter string against windows of the longer one. */
    case Partial = 'partial';

    /** Indel similarity after sorting the whitespace-separated words. */
    case TokenSort = 'tokenSort';

    /** Indel similarity of the shared words and the words unique to each string. Two empty strings score 0. */
    case TokenSet = 'tokenSet';

    /** Partial ratio after sorting the whitespace-separated words. */
    case PartialTokenSort = 'partialTokenSort';

    /** Partial ratio of the shared words and the words unique to each string. Two empty strings score 0. */
    case PartialTokenSet = 'partialTokenSet';

    /** The better of a sorted-word ratio and a shared-word ratio. */
    case Token = 'token';

    /** The better of a partial sorted-word ratio and a partial shared-word ratio. */
    case PartialToken = 'partialToken';

    /** FuzzyWuzzy weighted combination of the ratio family. Two empty strings score 0. */
    case Weighted = 'weighted';

    /** Whole-string ratio, except two empty strings score 0. */
    case Quick = 'quick';
}
