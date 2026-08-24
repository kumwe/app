<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Projection;

/**
 * Closed host-side reasons a Content value cannot become a trustworthy Studio projection.
 *
 * @since  2.0.0
 */
enum StudioProjectionRejection: string
{
    /**
     * The caller named no reversible Content model or entry identifier.
     *
     * @since  2.0.0
     */
    case InvalidIdentifier = 'invalid-identifier';

    /**
     * The authorized application service disclosed no such model or entry.
     *
     * @since  2.0.0
     */
    case Unavailable = 'unavailable';

    /**
     * A source schema has no deterministic equivalent in the pinned Studio content vocabulary.
     *
     * @since  2.0.0
     */
    case UnsupportedField = 'unsupported-field';

    /**
     * A source value cannot round-trip through the projected field without changing meaning.
     *
     * @since  2.0.0
     */
    case LossyValue = 'lossy-value';

    /**
     * The generated document failed the exact schema vendored from Studio.
     *
     * @since  2.0.0
     */
    case InvalidDocument = 'invalid-document';
}
