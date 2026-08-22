<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application;

/**
 * Decides whether extension code loaded by this process still belongs to the authoritative generation.
 *
 * Every delivery-neutral extension execution boundary depends on this small port instead of querying
 * lifecycle tables itself. That keeps the generation comparison identical for HTTP, MCP, synchronous
 * events and typed business handlers, and gives tests a deterministic seam for proving stale code is
 * never invoked.
 *
 * @since  2.0.0
 */
interface ExtensionExecutionGate
{
    /**
     * Report whether the exact boot-time extension generation may still execute.
     *
     * @return  bool  True only while local publication bytes and registry authority still match.
     *
     * @since   2.0.0
     */
    public function isCurrent(): bool;

    /**
     * Refuse extension execution after lifecycle authority has superseded this process.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When this process has no trusted generation or its generation is stale.
     *
     * @since   2.0.0
     */
    public function assertCurrent(): void;
}
