<?php

declare(strict_types=1);

namespace KumweContract\ManifestSix;

use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;

/**
 * The canonical composition documents the manifest-six compatibility package promises.
 *
 * Each factory returns the exact canonical bytes `kumwe.json` declares, because the strict registrar
 * compares the two byte for byte: any drift between this file and the signed manifest is a failed
 * activation, which is precisely the behaviour the generation promises to a real schema-6 package.
 *
 * @since  2.0.0
 */
final readonly class Definitions
{
    /**
     * Canonical bytes of the block-definition document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string BLOCK_DEFINITION =
        '{"accessibility":{"accessibleName":"not-applicable","category":"structural","keyboar'
        . 'd":{"defaultMessage":"Items follow the Blueprint reading order and can be moved thro'
        . 'ugh the outline.","key":"kumwe.contract-manifest-six/grid-keyboard"},"outputChecks":'
        . '["kumwe.contract-manifest-six/reading-order"],"reducedMotion":"not-applicable"},"cat'
        . 'egory":"kumwe.contract-manifest-six/layout","contractVersion":"0.1-draft","editingMo'
        . 'des":["blueprint","content"],"icon":{"kind":"symbol","value":"grid"},"kind":"block-d'
        . 'efinition","label":{"defaultMessage":"Grid","key":"kumwe.contract-manifest-six/grid"'
        . '},"owner":{"id":"kumwe.contract-manifest-six/blocks","version":"1.0.0"},"ports":[],"'
        . 'propertySchema":{"$schema":"https://json-schema.org/draft/2020-12/schema","additiona'
        . 'lProperties":false,"properties":{"collapse":{"enum":["preserve","wrap","stack"]},"co'
        . 'lumns":{"maximum":12,"minimum":1,"type":"integer"}},"required":["columns","collapse"'
        . '],"type":"object"},"rendererRequirements":[{"capability":"kumwe.contract-manifest-si'
        . 'x/grid","surface":"web","versions":"^1.0.0"},{"capability":"kumwe.contract-manifest-'
        . 'six/grid","surface":"preview","versions":"^1.0.0"}],"revision":"grid-block-r1","slot'
        . 's":[{"accepts":{"types":["kumwe.contract-manifest-six/price"]},"id":"items","label":'
        . '{"defaultMessage":"Items","key":"kumwe.contract-manifest-six/grid-items"},"maximum":'
        . '100,"minimum":0,"ordered":true}],"themeControls":["gap","alignment","surface"],"type'
        . '":"kumwe.contract-manifest-six/grid","version":"1.0.0"}';

    /**
     * Canonical bytes of the pattern document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PATTERN =
        '{"blockDependencies":[{"revision":"block-r1","type":"kumwe.contract-manifest-six/sec'
        . 'tion","version":"1.0.0"},{"revision":"block-r1","type":"kumwe.contract-manifest-six/'
        . 'text","version":"1.0.0"}],"contractVersion":"0.1-draft","description":{"defaultMessa'
        . 'ge":"A hero section followed by a caption paragraph.","key":"kumwe.contract-manifest'
        . '-six/hero-with-caption-help"},"id":"kumwe.contract-manifest-six/hero-with-caption","'
        . 'kind":"pattern","label":{"defaultMessage":"Hero with caption","key":"kumwe.contract-'
        . 'manifest-six/hero-with-caption"},"owner":{"id":"kumwe.contract-manifest-six/patterns'
        . '","version":"1.2.0"},"revision":"pattern-r3","roots":[{"authoring":{"mode":"structur'
        . 'al"},"bindings":{},"id":"section-p","properties":{},"slots":{"main":[{"authoring":{"'
        . 'mode":"designer"},"bindings":{},"id":"text-p","properties":{"text":"Hero"},"slots":{'
        . '},"type":"kumwe.contract-manifest-six/text","version":"1.0.0"}]},"type":"kumwe.contr'
        . 'act-manifest-six/section","version":"1.0.0"},{"authoring":{"mode":"designer"},"bindi'
        . 'ngs":{},"id":"text-q","properties":{"text":"Caption"},"slots":{},"type":"kumwe.contr'
        . 'act-manifest-six/text","version":"1.0.0"}],"version":"1.2.0"}';

    /**
     * Canonical bytes of the field-adapter document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FIELD_ADAPTER =
        '{"contractVersion":"0.1-draft","control":"kumwe.contract-manifest-six/money","fieldK'
        . 'inds":["kumwe.contract-manifest-six/money","kumwe.contract-manifest-six/decimal"],"i'
        . 'd":"kumwe.contract-manifest-six/money-control","kind":"field-adapter","label":{"defa'
        . 'ultMessage":"Money control","key":"kumwe.contract-manifest-six/money-control"},"opti'
        . 'onSchema":{"additionalProperties":false,"properties":{"currencyDisplay":{"enum":["co'
        . 'de","symbol"],"type":"string"}},"type":"object"},"owner":{"id":"kumwe.contract-manif'
        . 'est-six/catalog","version":"1.0.0"},"requiredCapability":"kumwe.contract-manifest-si'
        . 'x/field-controls","version":"1.0.0"}';

    /**
     * Canonical bytes of the inspector document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string INSPECTOR =
        '{"blockTypes":["kumwe.contract-manifest-six/price"],"contractVersion":"0.1-draft","i'
        . 'd":"kumwe.contract-manifest-six/price-inspector","kind":"inspector","label":{"defaul'
        . 'tMessage":"Price inspector","key":"kumwe.contract-manifest-six/price-inspector"},"ow'
        . 'ner":{"id":"kumwe.contract-manifest-six/catalog","version":"1.0.0"},"placement":"aug'
        . 'ment","requiredCapability":"kumwe.contract-manifest-six/custom-inspectors","version"'
        . ':"1.0.0"}';

    /**
     * Canonical bytes of the design-vocabulary document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DESIGN_VOCABULARY =
        '{"contractVersion":"0.1-draft","designControls":[{"choices":[{"id":"compact","label"'
        . ':{"defaultMessage":"Compact","key":"kumwe.contract-manifest-six/card-density-compact'
        . '"}},{"id":"comfortable","label":{"defaultMessage":"Comfortable","key":"kumwe.contrac'
        . 't-manifest-six/card-density-comfortable"}}],"id":"card-density","kind":"enum","label'
        . '":{"defaultMessage":"Card density","key":"kumwe.contract-manifest-six/card-density"}'
        . '},{"choices":[{"id":"full","label":{"defaultMessage":"Full","key":"kumwe.contract-ma'
        . 'nifest-six/card-width-full"}},{"id":"half","label":{"defaultMessage":"Half","key":"k'
        . 'umwe.contract-manifest-six/card-width-half"}},{"id":"quarter","label":{"defaultMessa'
        . 'ge":"Quarter","key":"kumwe.contract-manifest-six/card-width-quarter"}}],"id":"card-w'
        . 'idth","kind":"size-role","label":{"defaultMessage":"Card width","key":"kumwe.contrac'
        . 't-manifest-six/card-width"}}],"id":"kumwe.contract-manifest-six/product-vocabulary",'
        . '"kind":"design-vocabulary","label":{"defaultMessage":"Product design vocabulary","ke'
        . 'y":"kumwe.contract-manifest-six/product-vocabulary"},"owner":{"id":"kumwe.contract-m'
        . 'anifest-six/catalog","version":"1.0.0"},"recipes":[{"blockType":"kumwe.contract-mani'
        . 'fest-six/price","designValues":{"card-density":"compact","card-width":"quarter"},"id'
        . '":"price-compact","label":{"defaultMessage":"Compact price","key":"kumwe.contract-ma'
        . 'nifest-six/price-compact"}}],"version":"1.0.0"}';

    /**
     * Canonical bytes of the migration document, exactly as `kumwe.json` declares them.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MIGRATION =
        '{"artifactKinds":["blueprint","entry"],"contractVersion":"0.1-draft","description":{'
        . '"defaultMessage":"Rewrites plain price properties into the typed money value shape."'
        . ',"key":"kumwe.contract-manifest-six/price-to-money-description"},"id":"kumwe.contrac'
        . 't-manifest-six/price-to-money","kind":"migration","label":{"defaultMessage":"Price b'
        . 'ecomes typed money","key":"kumwe.contract-manifest-six/price-to-money"},"lossClassif'
        . 'ication":"lossless","owner":{"id":"kumwe.contract-manifest-six/catalog","version":"1'
        . '.1.0"},"sourceVersions":">=1.0.0 <1.1.0","targetVersion":"1.1.0","version":"1.1.0"}';

    /**
     * The block-definition document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function blockDefinition(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            self::BLOCK_DEFINITION,
        );
    }

    /**
     * The pattern document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function pattern(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::Pattern,
            self::PATTERN,
        );
    }

    /**
     * The field-adapter document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function fieldAdapter(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::FieldAdapter,
            self::FIELD_ADAPTER,
        );
    }

    /**
     * The inspector document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function inspector(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::Inspector,
            self::INSPECTOR,
        );
    }

    /**
     * The design-vocabulary document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function designVocabulary(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::DesignVocabulary,
            self::DESIGN_VOCABULARY,
        );
    }

    /**
     * The migration document this package promises, byte-identical to the signed manifest.
     *
     * @return  CanonicalCompositionDocument  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function migration(): CanonicalCompositionDocument
    {
        return new CanonicalCompositionDocument(
            CanonicalCompositionKind::Migration,
            self::MIGRATION,
        );
    }

    /**
     * The authoring capability this package declares for its inspector binding.
     *
     * @return  CapabilityDefinition  Declaration matching the manifest byte for byte.
     *
     * @since   2.0.0
     */
    public static function capability(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'kumwe.contract-manifest-six.compose',
            'Compose contract fixtures',
            'Author the canonical composition surface this fixture declares.',
        );
    }
}
