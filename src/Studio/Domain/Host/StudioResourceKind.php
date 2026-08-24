<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

/**
 * The two host-owned authoring resources a Studio session may address.
 *
 * @since  2.0.0
 */
enum StudioResourceKind: string
{
    /**
     * A host-owned portable Blueprint artifact.
     *
     * @since  2.0.0
     */
    case Blueprint = 'blueprint';

    /**
     * An App-owned Content model or entry projection.
     *
     * @since  2.0.0
     */
    case Content = 'content';
}
