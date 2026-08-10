<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Application;

use @@PHP_NAMESPACE@@\Integration\IntegrationLedger;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Supplies the transport-neutral overview model for both contributed surfaces.
 *
 * @since  2.0.0
 */
final readonly class OverviewService
{
    /**
     * Bind overview diagnostics to the shared bounded integration ledger.
     *
     * @param  IntegrationLedger  $ledger  Bounded process-local integration diagnostics.
     *
     * @since  2.0.0
     */
    public function __construct(private IntegrationLedger $ledger)
    {
    }

    /**
     * Build an overview only for a principal explicitly granted this component capability.
     *
     * @param   ExecutionContext  $context  Authenticated, surface-provenance execution context.
     *
     * @return  array{heading: string, message: string, activity: array<string, int|string|null>}
     *          Safe renderer data and bounded integration counts.
     *
     * @throws  InvalidArgumentException  When the context has no authorized principal.
     *
     * @since   2.0.0
     */
    public function overview(ExecutionContext $context): array
    {
        $principal = $context->principal();
        if ($principal === null || !$principal->hasCapability(Capability::fromString('@@EXTENSION_DOTTED@@.access'))) {
            throw new InvalidArgumentException('The @@EXTENSION_DOTTED@@.access capability is required.');
        }

        return [
            'heading' => '@@LABEL_PHP@@',
            'message' => 'The component is installed, trusted, and contributing through the typed runtime SPI.',
            'activity' => $this->ledger->snapshot(),
        ];
    }
}
