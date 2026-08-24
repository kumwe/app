<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Raised when an active block definition contradicts an immutable Blueprint dependency lock.
 *
 * A missing or withdrawn definition remains an unresolved contribution that Studio can represent.
 * An active, renderer-supported definition with the same type but different immutable coordinates is
 * materially different: silently omitting it would disguise registry drift as ordinary withdrawal.
 *
 * @since  2.0.0
 */
final class StudioCompositionLockMismatch extends RuntimeException
{
    /**
     * Name the conflicting type without exposing the complete Blueprint or contribution document.
     *
     * @param  string  $type  Locked block type contradicted by the active definition.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $type)
    {
        parent::__construct(sprintf(
            'The active Studio block definition for %s contradicts its immutable Blueprint lock.',
            $type,
        ));
    }
}
