<?php

declare(strict_types=1);

namespace KumweContract\ManifestSix;

use Kumwe\App\Extension\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Contribution\CanonicalCompositionRegistrar;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\App\Extension\Runtime\ExtensionContainer;
use LogicException;

/**
 * Compatibility provider for the manifest-6 generation and contribution SPI 4.
 *
 * SPI 4 replaces the frozen schema-5 paraphrases with canonical Studio contribution documents,
 * per decision D16 and kumwe/app#104. This provider registers one document of every canonical
 * kind, so the lifecycle fixture proves the whole declared surface is reachable from a package
 * and reconciled byte for byte against its signed manifest — not merely parsed.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider, ExtensionContributionProvider
{
    /**
     * Register nothing: the canonical composition contract is declarative and runs no services.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }

    /**
     * Contribute one canonical document of every composition kind.
     *
     * The canonical registrar is additive and feature-detected, exactly as a real schema-6 package
     * must do it; a shell without the capability fails loudly rather than silently dropping the
     * surface this generation exists to prove.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Registrar bound to this package's owner.
     * @param   ExtensionContainer              $container      Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @throws  LogicException  When the shell lacks the canonical composition capability.
     *
     * @since   2.0.0
     */
    public function contribute(ExtensionContributionRegistrar $contributions, ExtensionContainer $container): void
    {
        $contributions->capability(Definitions::capability());
        if (!$contributions instanceof CanonicalCompositionRegistrar) {
            throw new LogicException('The shell does not accept canonical composition contributions.');
        }
        $contributions->canonicalCompositionDocument(Definitions::blockDefinition());
        $contributions->canonicalCompositionDocument(Definitions::pattern());
        $contributions->canonicalCompositionDocument(Definitions::fieldAdapter());
        $contributions->canonicalCompositionDocument(Definitions::inspector());
        $contributions->canonicalCompositionDocument(Definitions::designVocabulary());
        $contributions->canonicalCompositionDocument(Definitions::migration());
    }
}
