<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkKind;

/**
 * Idempotent handler for one durable process work contract.
 *
 * @since  2.0.0
 */
interface ProcessWorkHandler
{
    /** @return bool Whether this handler owns the kind/name pair. @since 2.0.0 */
    public function supports(ProcessWorkKind $kind, string $name): bool;

    /** @return void @since 2.0.0 */
    public function handle(ProcessWorkLease $lease, ExecutionContext $context): void;
}
