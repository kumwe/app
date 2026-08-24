<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Artifact;

/**
 * Closed reasons a Studio document cannot enter host-owned persistence.
 *
 * @since  2.0.0
 */
enum StudioStoredDocumentRejection: string
{
    /**
     * A member name declares executable or presentation content.
     *
     * @since  2.0.0
     */
    case UnsafeMember = 'unsafe-member';

    /**
     * A string contains markup or executable syntax.
     *
     * @since  2.0.0
     */
    case ExecutableContent = 'executable-content';

    /**
     * A style-shaped field contains a CSS declaration.
     *
     * @since  2.0.0
     */
    case StyleContent = 'style-content';

    /**
     * A URL appears outside a schema-defined URL-shaped member.
     *
     * @since  2.0.0
     */
    case OutOfSchemaUrl = 'out-of-schema-url';

    /**
     * A URL uses an unsafe scheme, credential or private host.
     *
     * @since  2.0.0
     */
    case UnsafeUrl = 'unsafe-url';
}
