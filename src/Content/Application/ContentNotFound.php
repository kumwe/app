<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use RuntimeException;

/**
 * Raised when a content entry the caller named does not exist within reach of the current context.
 *
 * `ContentService` turns a repository miss into this single name so that delivery code has one thing
 * to catch, whether the row is absent, belongs to another site, or is trashed while the caller asked
 * for live entries only. The message names the identifier and nothing about the stored content, so it
 * is safe to log or surface to an operator.
 *
 * @since  2.0.0
 */
final class ContentNotFound extends RuntimeException
{
    /**
     * Describe the content entry that could not be resolved.
     *
     * @param  string  $id  UUID the lookup asked for, quoted back to the operator in the message.
     *
     * @since  2.0.0
     */
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Content entry %s was not found.', $id));
    }
}
