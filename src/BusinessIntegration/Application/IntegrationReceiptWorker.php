<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

/**
 * Host worker entry point for receipts materialized by the runtime fanout transport.
 *
 * @since  2.0.0
 */
interface IntegrationReceiptWorker
{
    /**
     * Claim and execute one fair receipt without coupling its outcome to other consumers.
     *
     * @param   string  $workerId      Unique replica and process identity.
     * @param   string  $generation    Current trusted runtime generation.
     * @param   int     $leaseSeconds  Maximum receipt lease, narrowed by its queue policy.
     *
     * @return  bool  Whether a receipt was attempted; failures retain their own retry/dead-letter state.
     *
     * @since   2.0.0
     */
    public function dispatchOne(string $workerId, string $generation, int $leaseSeconds = 60): bool;
}
