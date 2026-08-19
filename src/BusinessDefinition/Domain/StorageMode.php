<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * How the records of a business entity are physically kept.
 *
 * Kumwe materializes a published definition as real relational tables and offers no second strategy, so
 * this type has exactly one case today. It stays an explicit, declared property because the mode is part
 * of the canonical payload a version is checksummed over: naming it keeps already-published documents
 * readable if another strategy is ever added, and gives the compatibility analyzer a path to classify —
 * a change of storage mode between two versions is reported as destructive.
 *
 * @since  2.0.0
 */
enum StorageMode: string
{
    /**
     * Records live in the relational tables the physical schema compiler derives from the definition.
     *
     * @since  2.0.0
     */
    case Relational = 'relational';
}
