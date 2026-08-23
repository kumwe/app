<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * The canonical Studio contribution kinds a manifest schema 6 package may declare.
 *
 * Each case names one published `@kumwe/studio-protocol` document schema, vendored at the exact
 * pinned release under `resources/studio-contract/protocol/schemas/`. Schema 6 carries these
 * canonical documents instead of the App paraphrases manifest 5 froze, per decision D16 and
 * kumwe/app#104; the frozen schema-5 vocabulary stays exactly as released beside them.
 *
 * @since  2.0.0
 */
enum CanonicalCompositionKind: string
{
    /**
     * A placeable block with its complete canonical `propertySchema` document.
     *
     * @since  2.0.0
     */
    case BlockDefinition = 'block-definition';

    /**
     * A reusable structure arranged from declared blocks.
     *
     * @since  2.0.0
     */
    case Pattern = 'pattern';

    /**
     * An editing control adapting one field kind to the authoring surface.
     *
     * @since  2.0.0
     */
    case FieldAdapter = 'field-adapter';

    /**
     * An inspector panel opened for declared block types.
     *
     * @since  2.0.0
     */
    case Inspector = 'inspector';

    /**
     * A design vocabulary of tokens, recipes and size roles a theme remaps.
     *
     * @since  2.0.0
     */
    case DesignVocabulary = 'design-vocabulary';

    /**
     * A document migration stepping declared artifacts between revisions.
     *
     * @since  2.0.0
     */
    case Migration = 'migration';

    /**
     * The member that carries a document's identity within this kind.
     *
     * @return  string  `type` for a block definition, `id` for every other kind, as the pinned
     *          schemas declare.
     *
     * @since   2.0.0
     */
    public function identityMember(): string
    {
        return $this === self::BlockDefinition ? 'type' : 'id';
    }
}
