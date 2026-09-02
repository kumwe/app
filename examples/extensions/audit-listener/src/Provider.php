<?php

declare(strict_types=1);

namespace KumweExample\AuditListener;

use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use KumweExample\AuditListener\Integration\AuditLedger;
use KumweExample\AuditListener\Integration\MutationAuditListener;
use LogicException;

/**
 * Composes the audit ledger and binds the executable listener to the declaration the manifest signed.
 *
 * Every declaration lives in `kumwe.json`; the host parses that manifest once and admits only bindings
 * whose identifiers match declarations this package owns. The provider therefore registers one shared
 * service and attaches one handler — the whole of what an event observer is.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionBindingProvider
{
    /** @var string @since 2.0.0 */
    private const LEDGER = 'extension.kumwe.audit-listener-example.ledger';

    /** @var string @since 2.0.0 */
    private const LISTENER = 'kumwe.audit-listener-example.record-mutations';

    /** @inheritDoc */
    public function register(ExtensionContainer $container): void
    {
        $container->share(
            self::LEDGER,
            static fn (ExtensionContainer $container): AuditLedger => new AuditLedger(),
        );
    }

    /** @inheritDoc */
    public function bind(ExtensionBindingRegistrar $bindings, ExtensionContainer $container): void
    {
        $ledger = $container->get(self::LEDGER);
        if (!$ledger instanceof AuditLedger) {
            throw new LogicException('The audit listener ledger is unavailable.');
        }

        $bindings->domainListener(self::LISTENER, new MutationAuditListener(self::LISTENER, $ledger));
    }
}
