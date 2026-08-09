<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Presentation;

use Kumwe\CMS\Portal\Application\PortalSession;

/**
 * Object-capability renderer fixed to one contributed route's owner and declared portal template.
 *
 * Extension factories receive this wrapper rather than `PortalRenderer`, so they can supply view data
 * but cannot name another extension, another template, or a core portal template.
 *
 * @since  2.0.0
 */
final readonly class PortalContributionRenderer
{
    /**
     * Bind a rendering capability to one owner and one template declaration.
     *
     * @param  PortalRenderer  $renderer   Shared isolated portal renderer, retained privately.
     * @param  string          $extension  Canonical owner identifier chosen by the registry.
     * @param  string          $template   Owned template identifier chosen by the route declaration.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalRenderer $renderer,
        private string $extension,
        private string $template,
    ) {
    }

    /**
     * Render the one template this capability was created for.
     *
     * @param   array<string, mixed>  $data     Extension view data.
     * @param   PortalSession         $session  Live portal session.
     *
     * @return  string  Rendered portal document.
     *
     * @since   2.0.0
     */
    public function render(array $data, PortalSession $session): string
    {
        return $this->renderer->renderExtension($this->extension, $this->template, $data, $session);
    }
}
