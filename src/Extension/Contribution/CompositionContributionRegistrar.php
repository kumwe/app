<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * Additive capability for packages that contribute to the visual composition surface.
 *
 * This interface is separate from the frozen contribution SPI registrars so every existing provider
 * stays source compatible: a package that composes nothing never sees it, and one that declares blocks,
 * patterns, field controls, inspectors, design vocabulary or composition migrations requires this
 * capability explicitly before those declarations are reconciled. The concrete owner-bound registrar
 * implements it alongside the others, so an extension declares its composition surface through exactly
 * the path it declares capabilities, routes and business definitions through.
 *
 * It exists because the composition contribution contract is frozen at Gate A while the surface that
 * consumes it is Gate B, per decision D16: everything registered here is declarative and inert, held to
 * the signed manifest at admission and at install, and consumed by nothing until the surface ships.
 *
 * @since  2.0.0
 */
interface CompositionContributionRegistrar
{
    /**
     * Publish one manifest-reconciled block with its bounded properties, slots and renderer binding.
     *
     * @param   CompositionBlockDeclaration  $declaration  Owner-bound declaration of one placeable block.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionBlock(CompositionBlockDeclaration $declaration): void;

    /**
     * Publish one manifest-reconciled pattern arranged from this owner's declared blocks.
     *
     * @param   CompositionPatternDeclaration  $declaration  Owner-bound declaration of one reusable structure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionPattern(CompositionPatternDeclaration $declaration): void;

    /**
     * Publish one manifest-reconciled editing control for a published property type.
     *
     * @param   CompositionFieldControlDeclaration  $declaration  Owner-bound declaration of one field control.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionFieldControl(CompositionFieldControlDeclaration $declaration): void;

    /**
     * Publish one manifest-reconciled inspector for one of this owner's declared blocks.
     *
     * @param   CompositionInspectorDeclaration  $declaration  Owner-bound declaration of one inspector panel.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionInspector(CompositionInspectorDeclaration $declaration): void;

    /**
     * Publish one manifest-reconciled design vocabulary of tokens, recipes and size roles.
     *
     * @param   CompositionDesignVocabularyDeclaration  $declaration  Owner-bound vocabulary a theme remaps.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionDesignVocabulary(CompositionDesignVocabularyDeclaration $declaration): void;

    /**
     * Publish one manifest-reconciled migration for documents a declared block appears in.
     *
     * @param   CompositionMigrationDeclaration  $declaration  Owner-bound declared document migration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionMigration(CompositionMigrationDeclaration $declaration): void;
}
