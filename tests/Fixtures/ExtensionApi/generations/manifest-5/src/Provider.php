<?php

declare(strict_types=1);

namespace KumweContract\ManifestFive;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Contribution\CompositionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use LogicException;

/**
 * Compatibility provider for the manifest-5 generation and contribution SPI 3.
 *
 * SPI 3 is the generation that froze the composition contribution contract at Gate A: blocks with
 * bounded property schemas, patterns, field controls, inspectors, design vocabulary and composition
 * migrations, all declarative and all inert until the Gate B surface consumes them. This provider
 * registers one of each, so the lifecycle fixture proves the whole declared surface is reachable from
 * a package and reconciled against its signed manifest — not merely parsed.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider, ExtensionContributionProvider
{
    /**
     * Register nothing: the composition contract is declarative and this package runs no services.
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
     * Contribute one declaration of every composition contribution kind.
     *
     * The composition registrar is additive and feature-detected, exactly as a real schema-5 package
     * must do it; a shell without the capability fails loudly rather than silently dropping the surface
     * this generation exists to prove.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Registrar bound to this package's owner.
     * @param   ExtensionContainer              $container      Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @throws  LogicException  When the shell offers no composition contribution registrar.
     *
     * @since   2.0.0
     */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        if (!$contributions instanceof CompositionContributionRegistrar) {
            throw new LogicException('The manifest-five compatibility package requires composition contributions.');
        }
        $contributions->compositionBlock(Definitions::block());
        $contributions->compositionPattern(Definitions::pattern());
        $contributions->compositionFieldControl(Definitions::fieldControl());
        $contributions->compositionInspector(Definitions::inspector());
        $contributions->compositionDesignVocabulary(Definitions::vocabulary());
        $contributions->compositionMigration(Definitions::migration());
    }
}
