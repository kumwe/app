<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain;

/**
 * Kind of extension a package declares, taken from the `type` field of its manifest.
 *
 * The value is fixed when the package is first installed and an upgrade that disagrees with it is
 * refused, so this classifies what an installed package *is* rather than what it currently does.
 * Most of the lifecycle treats every kind alike; `Template` is the exception, because activating one
 * binds it to a site or administrator theme surface and an upgrade clears those bindings again.
 *
 * @since  2.0.0
 */
enum ExtensionType: string
{
    /**
     * Extension that reacts to events raised elsewhere instead of owning a delivery surface.
     *
     * @since  2.0.0
     */
    case Plugin = 'plugin';
    /**
     * Extension that renders a self-contained fragment a template places within a page.
     *
     * @since  2.0.0
     */
    case Module = 'module';
    /**
     * Extension that supplies a theme; the only kind whose activation names a site or administrator surface.
     *
     * @since  2.0.0
     */
    case Template = 'template';
    /**
     * Extension that owns a feature area end to end, including its routes, views, and workspace.
     *
     * @since  2.0.0
     */
    case Component = 'component';
    /**
     * Extension that exists to deliver a related set of other extensions as one installable unit.
     *
     * @since  2.0.0
     */
    case Package = 'package';
    /**
     * Extension that ships translations for surfaces other extensions provide.
     *
     * @since  2.0.0
     */
    case Language = 'language';
}
