<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

/**
 * Raised when a content type or workflow definition the caller named is not published for the site.
 *
 * The content model is versioned and site-scoped, so a lookup misses for three different reasons: the
 * handle or UUID is unknown, the definition belongs to another site, or the specific version asked for
 * was never published. One exception covers both kinds of definition; the constructor carries which
 * kind was wanted so callers never have to compose the operator-facing wording themselves.
 *
 * @since  2.0.0
 */
final class ContentModelNotFound extends \RuntimeException
{
    /**
     * Describe the definition that could not be resolved.
     *
     * @param  string  $kind        Kind of definition in lowercase words, such as `content type` or `workflow`.
     * @param  string  $identifier  Handle or UUID the lookup asked for.
     * @param  ?int    $version     Version that was demanded, or null when the published head was wanted.
     *
     * @since  2.0.0
     */
    public function __construct(string $kind, string $identifier, ?int $version = null)
    {
        parent::__construct(sprintf(
            '%s "%s"%s was not found.',
            ucfirst($kind),
            $identifier,
            $version === null ? '' : ' version ' . $version,
        ));
    }
}
