<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;

/**
 * Last-resort administrator renderer that draws only from the protected core templates.
 *
 * `AdministratorRenderer` falls back to this whenever a themed page or an extension view raises a Twig
 * error, so an operator can still reach the back office and recover whatever broke it. The isolation is
 * structural rather than conditional: the environment it holds carries no theme path, no extension
 * namespace, and no loader an operator can influence, so nothing installed can make the fallback fail
 * for the same reason the first attempt did.
 *
 * @since  2.0.0
 */
final readonly class RecoveryAdministratorRenderer
{
    /**
     * Bind the fallback renderer to the theme-free core administrator environment.
     *
     * @param  RecoveryAdministratorTwigEnvironment  $twig  Environment resolving `@core-admin` templates alone.
     *
     * @since  2.0.0
     */
    public function __construct(private RecoveryAdministratorTwigEnvironment $twig)
    {
    }

    /**
     * Render one core administrator template with the view data exactly as the caller assembled it.
     *
     * Nothing is added on the caller's behalf, unlike `AdministratorRenderer::render()`:
     * `AdministratorRenderer` has already rebuilt the shell data against a core-only navigation registry
     * before falling back here, and assembling it a third time would put the contributed menu this path
     * exists to escape back into the page.
     *
     * @param   string                $template  Core template name without the `.twig` suffix.
     * @param   array<string, mixed>  $data      View variables for the template, by Twig variable name.
     *
     * @return  string  The rendered HTML document.
     *
     * @since   2.0.0
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template . '.twig', $data);
    }
}
