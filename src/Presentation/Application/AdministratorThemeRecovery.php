<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

/**
 * Break-glass port for restoring the protected built-in administrator theme.
 *
 * A third-party administrator theme that renders badly locks every operator out of the back office, and
 * the back office is the only place the activation would normally be reversed. This port exists so that
 * the escape hatch lives outside the request path altogether: implementations are wired to the confirmed
 * `theme:administrator:recover` console command and are never exposed through an HTTP handler, so the
 * recovery cannot itself be driven by whoever caused the lockout.
 *
 * @since  2.0.0
 */
interface AdministratorThemeRecovery
{
    /**
     * Clear the administrator theme activation so the built-in theme renders again.
     *
     * Implementations take whatever locks the extension registry demands and audit the reset, and are
     * expected to be atomic: a failure part way through leaves the previous activation in force rather
     * than an administrator surface with no theme at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recover(): void;
}
