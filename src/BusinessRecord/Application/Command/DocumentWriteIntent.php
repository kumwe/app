<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

/**
 * Whether a document command brings a document into existence or moves one that already exists.
 *
 * The two differ in exactly one place — where the aggregate version comes from — and stating that as a
 * declared intent rather than inferring it from a nullable version keeps a caller from creating a second
 * document when it believed it was amending the first. Everything after that point is shared: the same
 * validation, the same aggregate invariants, the same single transaction, the same one outcome.
 *
 * @since  2.0.0
 */
enum DocumentWriteIntent: string
{
    /**
     * The document does not exist yet and is written whole, header and lines together, at version one.
     *
     * @since  2.0.0
     */
    case Create = 'create';

    /**
     * The document exists at the version the command names, and is replaced whole at the next version.
     *
     * @since  2.0.0
     */
    case Amend = 'amend';
}
