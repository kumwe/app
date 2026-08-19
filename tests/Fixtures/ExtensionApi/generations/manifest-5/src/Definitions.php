<?php

declare(strict_types=1);

namespace KumweContract\ManifestFive;

use Kumwe\App\Extension\Contribution\CompositionBlockDeclaration;
use Kumwe\App\Extension\Contribution\CompositionDesignVocabularyDeclaration;
use Kumwe\App\Extension\Contribution\CompositionFieldControlDeclaration;
use Kumwe\App\Extension\Contribution\CompositionInspectorDeclaration;
use Kumwe\App\Extension\Contribution\CompositionMigrationDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPatternDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPropertySchema;
use Kumwe\App\Extension\Contribution\CompositionPropertyType;

/**
 * The composition declarations the manifest-five compatibility package promises.
 *
 * Each factory rebuilds, in code, exactly what `kumwe.json` declares, because the strict registrar
 * compares the two field for field: a drift between this file and the manifest is a failed activation,
 * which is precisely the behaviour the generation promises to a real package.
 *
 * @since  2.0.0
 */
final readonly class Definitions
{
    /**
     * The callout block with its bounded property schema, slots and renderer binding.
     *
     * @return  CompositionBlockDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function block(): CompositionBlockDeclaration
    {
        return new CompositionBlockDeclaration(
            'kumwe.contract-manifest-five.callout',
            new CompositionPropertySchema([
                'body' => ['type' => 'text', 'required' => false, 'maximum_length' => 4000],
                'columns' => ['type' => 'integer', 'required' => false, 'maximum' => 4, 'minimum' => 1],
                'emphasis' => ['type' => 'choice', 'required' => true, 'values' => ['muted', 'standard', 'strong']],
                'heading' => ['type' => 'string', 'required' => true, 'maximum_length' => 120],
                'illustration' => ['type' => 'reference', 'required' => false, 'kind' => 'media'],
                'show_icon' => ['type' => 'boolean', 'required' => false],
            ]),
            ['aside', 'body'],
            'kumwe.contract-manifest-five.callout-renderer',
            2,
        );
    }

    /**
     * The pattern arranging the callout block twice.
     *
     * @return  CompositionPatternDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function pattern(): CompositionPatternDeclaration
    {
        return new CompositionPatternDeclaration(
            'kumwe.contract-manifest-five.callout-pair',
            ['kumwe.contract-manifest-five.callout', 'kumwe.contract-manifest-five.callout'],
        );
    }

    /**
     * The editing control declared for the profile's choice property type.
     *
     * @return  CompositionFieldControlDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function fieldControl(): CompositionFieldControlDeclaration
    {
        return new CompositionFieldControlDeclaration(
            'kumwe.contract-manifest-five.emphasis-picker',
            CompositionPropertyType::Choice,
        );
    }

    /**
     * The inspector declared for the callout block.
     *
     * @return  CompositionInspectorDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function inspector(): CompositionInspectorDeclaration
    {
        return new CompositionInspectorDeclaration(
            'kumwe.contract-manifest-five.callout-inspector',
            'kumwe.contract-manifest-five.callout',
        );
    }

    /**
     * The design vocabulary of tokens, one recipe, and the size roles a theme remaps.
     *
     * @return  CompositionDesignVocabularyDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function vocabulary(): CompositionDesignVocabularyDeclaration
    {
        return new CompositionDesignVocabularyDeclaration(
            'kumwe.contract-manifest-five.vocabulary',
            ['accent', 'surface'],
            ['callout-card'],
            ['gutter', 'measure'],
        );
    }

    /**
     * The migration stepping callout documents from revision one to revision two.
     *
     * @return  CompositionMigrationDeclaration  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function migration(): CompositionMigrationDeclaration
    {
        return new CompositionMigrationDeclaration(
            'kumwe.contract-manifest-five.callout-1-2',
            'kumwe.contract-manifest-five.callout',
            1,
            2,
            [
                ['action' => 'rename', 'property' => 'title', 'to' => 'heading'],
                ['action' => 'remove', 'property' => 'legacy_style'],
            ],
        );
    }
}
