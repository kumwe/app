<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Install;

/**
 * Narrow port for settling extension installs that were interrupted before their outcome was known.
 *
 * Recovery is separated from `ExtensionManager` on purpose: reconciling takes no operator input and
 * authorizes nobody, so the startup path can depend on this alone. `MaterializeExtensionRuntimeCommand`
 * and the runtime watcher both reconcile before compiling a runtime map and then refuse to compile
 * while anything remains pending, which is what keeps a replica from publishing an extension set
 * derived from a half-finished install.
 *
 * @since  2.0.0
 */
interface ExtensionInstallReconciler
{
    /**
     * Settle the install operations whose outcome is still unresolved.
     *
     * A pass may be bounded and may leave operations it could not decide, so a non-zero return does not
     * mean the backlog is clear — ask `hasPending()` afterwards rather than inferring completion here.
     *
     * @return  int  How many operations this pass moved to a settled outcome; 0 when nothing was pending
     *          or nothing could be decided.
     *
     * @since   2.0.0
     */
    public function reconcile(): int;

    /**
     * Report whether any install operation is still waiting to be settled.
     *
     * @return  bool  True while at least one operation's outcome is unresolved, which callers treat as a
     *          reason to fail closed rather than to carry on.
     *
     * @since   2.0.0
     */
    public function hasPending(): bool;
}
