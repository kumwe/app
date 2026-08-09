<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;

/**
 * Resolves immutable field-type structure without implying that its owner is executable.
 *
 * Validating a definition and compiling its physical schema both need the shape of a field type long after
 * the extension that contributed it stopped running, because rows already written under that type still
 * have to be read and migrated. Implementations therefore answer from whichever authoritative record they
 * hold — the in-memory contribution set, or checksum-verified persisted history — and never treat "not
 * currently active" as "not resolvable". Structure resolved here confers no permission to execute the
 * owning extension's code.
 *
 * @since  2.0.0
 */
interface FieldTypeDefinitionResolver
{
    /**
     * Resolve the structure registered under a field-type identifier.
     *
     * Absence is a failure rather than a null result: a definition that names a type nobody can vouch for
     * must not validate, so implementations raise instead of degrading to a default shape.
     *
     * @param   string  $identifier  Namespaced field-type identifier, such as `core.text`.
     *
     * @return  FieldTypeDefinition  The structure the identifier was registered with.
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the identifier is unresolvable.
     *
     * @since   2.0.0
     */
    public function get(string $identifier): FieldTypeDefinition;
}
