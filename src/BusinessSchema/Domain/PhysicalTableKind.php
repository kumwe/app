<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

/**
 * Role a generated table plays inside a compiled business schema.
 *
 * Every `PhysicalTableBlueprint` carries one of these, and the value travels with the persisted blueprint
 * document, so a later plan, live introspection, or backup-acceptance check can tell a definition's own
 * record table apart from the tables generated to carry its relationships and ordered lines. It classifies
 * where the table came from, not how it is stored.
 *
 * @since  2.0.0
 */
enum PhysicalTableKind: string
{
    /**
     * The record table an entity-type definition compiles to, keyed by canonical record identity.
     *
     * @since  2.0.0
     */
    case Entity = 'entity';

    /**
     * A relationship table that stands on its own identity rather than on its endpoint pair.
     *
     * No compiler path emits this kind today; generated relationship storage is classified as `Junction`.
     *
     * @since  2.0.0
     */
    case Relation = 'relation';

    /**
     * A generated link table whose composite key is the source and target record identities.
     *
     * @since  2.0.0
     */
    case Junction = 'junction';

    /**
     * A child table of ordered lines keyed by its own line identity and cascaded from its owner.
     *
     * @since  2.0.0
     */
    case OwnedLine = 'owned_line';
}
