<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Which authority a business definition answers to, and with it the namespace its handles live under.
 *
 * The type is stored beside the owner identifier on every catalog row and travels in the canonical
 * payload, and it settles more than provenance. `DefinitionOwner` derives the reserved handle prefix from
 * it and validates the identifier against a shape chosen by it; package synchronization only ever writes
 * or deprecates `Extension` rows; and the administrator saves drafts and changes version status for
 * `Site` definitions alone, because the other two are owned by whoever supplied them.
 *
 * @since  2.0.0
 */
enum DefinitionOwnerType: string
{
    /**
     * Declared by the platform itself and shipped with the release, under the `core` namespace.
     *
     * @since  2.0.0
     */
    case Core = 'core';
    /**
     * Contributed by an installed package, under the namespace its `vendor/package` name reserves.
     *
     * Install and upgrade write these rows inside the extension transaction, so their publication status
     * follows the package lifecycle rather than an operator's lifecycle command: dropping a declaration
     * from a manifest deprecates the definition instead of erasing it.
     *
     * @since  2.0.0
     */
    case Extension = 'extension';
    /**
     * Authored inside one site through the administrator, under a `site.` prefixed namespace.
     *
     * The only kind an operator edits directly, and the only kind whose versions may be superseded,
     * deprecated, or rejected on request.
     *
     * @since  2.0.0
     */
    case Site = 'site';
}
