<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

/**
 * Lifecycle state of an installed extension as recorded in the extension registry.
 *
 * Only `Active` extensions are compiled into the runtime map that the request path reads, so this
 * value decides whether an installed extension can contribute services, routes, or templates. An
 * upgrade always returns a record to `Disabled` so that the operator re-activates deliberately.
 *
 * @since  2.0.0
 */
enum ExtensionStatus: string
{
    /**
     * Installed and present on disk, but excluded from the compiled runtime map.
     *
     * @since  2.0.0
     */
    case Disabled = 'disabled';

    /**
     * Installed, verified, and contributing to the compiled runtime map.
     *
     * @since  2.0.0
     */
    case Active = 'active';

    /**
     * Retained in the registry after activation failed, so an operator can diagnose it.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';
}
