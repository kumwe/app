<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;

/**
 * A published business definition paired with the physical schema installed for it.
 *
 * Every record read and write needs both halves at once: the definition that describes the shape, and the
 * installation row that says which shape the tables on disk actually have. `BusinessRecordDefinitionResolver`
 * produces these pairs and the record repositories accept nothing else, so a table is never addressed through
 * a definition the installer never applied. Construction re-proves the pairing rather than trusting the
 * resolver: the two halves must name the same definition and site, the pinned version may not run ahead of
 * the installed one, and a pair pinned to the installed version must match its recorded checksum. A pinned
 * version older than the installed one is accepted deliberately, because a stored row is decoded with the
 * shape it was written under.
 *
 * @since  2.0.0
 */
final readonly class ResolvedBusinessDefinition
{
    /**
     * Pair a definition with an installation and reject the pairing if the two disagree.
     *
     * @param   EntityTypeDefinition  $definition    Published definition version the caller intends to work
     *          with, which may be older than the installed one when reading history.
     * @param   SchemaInstallation    $installation  Installation row describing the tables that exist for that
     *          definition on this site.
     *
     * @throws  InvalidArgumentException  When the two halves name a different definition or site, the pinned
     *          version is newer than the installed one, or a pair pinned to the installed version carries a
     *          checksum the installation does not record.
     *
     * @since   2.0.0
     */
    public function __construct(
        public EntityTypeDefinition $definition,
        public SchemaInstallation $installation,
    ) {
        if (
            $definition->id !== $installation->definitionId
            || $definition->definitionVersion > $installation->definitionVersion
            || $definition->siteIdentifier !== $installation->siteIdentifier
        ) {
            throw new InvalidArgumentException('A resolved business definition and installed schema are inconsistent.');
        }
        if (
            $definition->definitionVersion === $installation->definitionVersion
            && !hash_equals($definition->checksum(), $installation->definitionChecksum)
        ) {
            throw new InvalidArgumentException('A resolved definition checksum differs from the installed schema.');
        }
    }
}
