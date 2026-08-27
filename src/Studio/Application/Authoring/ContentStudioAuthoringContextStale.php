<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use RuntimeException;

/**
 * Transport-neutral signal that an authorized context no longer names the current target generation.
 *
 * Only a caller whose authenticated scope matched and whose target was freshly authorized can receive
 * this distinction. A future canonical HTTP adapter may map it to Studio's published conflict contract;
 * this class defines no wire category, status code, or serializer.
 *
 * @since  2.0.0
 */
final class ContentStudioAuthoringContextStale extends RuntimeException
{
    /**
     * Retain the fresh App-native target for an authorized conflict response or context renewal.
     *
     * @param  ContentStudioAuthoringTarget  $current  Freshly authorized target generation.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ContentStudioAuthoringTarget $current)
    {
        parent::__construct('The Studio Content authoring context is stale.');
    }
}
