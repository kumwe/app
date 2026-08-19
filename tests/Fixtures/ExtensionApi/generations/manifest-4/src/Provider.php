<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour;

use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\Extension\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\App\Extension\Runtime\ExtensionContainer;
use KumweContract\ManifestFour\Integration\ObservationConsumer;
use KumweContract\ManifestFour\Integration\ObservationLedger;
use KumweContract\ManifestFour\Integration\ObservationListener;
use KumweContract\ManifestFour\Integration\ObservationProjectionBuilder;
use KumweContract\ManifestFour\Integration\ObservationWebhookTransport;
use KumweContract\ManifestFour\Integration\SummarizeJob;
use LogicException;

/**
 * Compatibility provider for the manifest-4 generation and contribution SPI 2.
 *
 * SPI 2 is the generation that added durable integration: versioned event contracts, synchronous
 * listeners, queue-backed consumers, jobs, queues, schedules, projections, reports and outbound
 * adapters. This provider registers one of each, so the lifecycle fixture proves the whole SPI-2
 * surface is still reachable from a package rather than only the parts core happens to exercise.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider, ExtensionContributionProvider
{
    /**
     * Identifier the package's own shared evidence sink is registered under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEDGER = 'extension.kumwe.contract-manifest-four.ledger';

    /**
     * Register the package's own evidence sink through the restricted container.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
        $container->share(
            self::LEDGER,
            static fn (ExtensionContainer $container): ObservationLedger => new ObservationLedger(),
        );
    }

    /**
     * Contribute the declared capability, policy and complete SPI-2 integration surface.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Registrar bound to this package's owner.
     * @param   ExtensionContainer              $container      Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @throws  LogicException  When the package's own evidence sink cannot be resolved.
     *
     * @since   2.0.0
     */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $ledger = $container->get(self::LEDGER);
        if (!$ledger instanceof ObservationLedger) {
            throw new LogicException('The manifest-four compatibility ledger is unavailable.');
        }
        $contributions->capability(new CapabilityDefinition(
            'kumwe.contract-manifest-four.view',
            'View the manifest-four compatibility surface',
            'Read the reports the manifest-four compatibility package contributes.',
        ));
        $contributions->resourcePolicy(new ResourcePolicyDefinition(
            'kumwe.contract-manifest-four.reader',
            'kumwe.contract-manifest-four.view',
            [
                new ResourcePolicyTarget('administrator_session'),
                new ResourcePolicyTarget('business_report', ['kumwe.contract-manifest-four.summary']),
            ],
        ));
        $contributions->eventSchema(Definitions::eventSchema());
        $listener = Definitions::domainListener();
        $contributions->domainListener($listener, new ObservationListener($listener, $ledger));
        $consumer = Definitions::consumer();
        $contributions->eventConsumer($consumer, new ObservationConsumer($consumer, $ledger));
        $contributions->jobHandler(Definitions::job(), new SummarizeJob($ledger));
        $contributions->queue(Definitions::queue());
        $contributions->schedule(Definitions::schedule());
        $projection = Definitions::projection();
        $contributions->projection($projection, new ObservationProjectionBuilder($projection));
        $contributions->report(Definitions::report());
        $contributions->webhook(Definitions::webhook(), new ObservationWebhookTransport($ledger));
    }
}
