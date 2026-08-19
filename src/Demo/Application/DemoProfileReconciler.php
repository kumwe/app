<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Application;

/**
 * Application boundary that converges the persisted installation-profile selections after migration.
 *
 * Delivery invokes this port without knowing whether manifests live on disk or how fixture ownership is
 * recorded. Implementations preserve frozen selections, content and definition customizations, and
 * append-only business operation and policy checkpoints according to each dataset's explicit contract.
 *
 * @since  2.0.0
 */
interface DemoProfileReconciler
{
    /**
     * Reconcile every configured demo dataset and return concise operator diagnostics.
     *
     * @return  list<string>  Messages describing work performed; empty when every manifest was current.
     *
     * @since   2.0.0
     */
    public function reconcile(): array;
}
