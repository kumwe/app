<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

/**
 * An editing control a package declares for one property type of the published composition profile.
 *
 * When the Gate B authoring surface opens a block for editing, every property is edited through a
 * control; the profile ships defaults, and a package may declare a richer one — a palette for a choice,
 * a slider for a number — under its own identity. The declaration binds the control to exactly one
 * property type from the closed vocabulary, so what it claims to edit is a checkable fact rather than a
 * runtime discovery, and a control for a type the profile does not publish is refused at admission.
 *
 * Nothing executable is declared: the control's implementation is an authoring-surface concern that
 * arrives with Gate B, and this declaration is what lets that arrival change nothing for a package
 * published today.
 *
 * @since  2.0.0
 */
final readonly class CompositionFieldControlDeclaration implements ContributionDefinition
{
    /**
     * Declare one field control and the property type it edits.
     *
     * @param   string                   $controlId  Namespaced identifier inside the declaring
     *          package's namespace.
     * @param   CompositionPropertyType  $edits      Published property type this control edits.
     * @param   int                      $version    Declared control revision, one or higher.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced or the version is not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $controlId,
        public CompositionPropertyType $edits,
        private int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $controlId) !== 1) {
            throw new InvalidArgumentException('A composition field control identifier must be namespaced.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException(
                'A composition field control version must be a positive integer.',
            );
        }
    }

    /**
     * The identifier this control is registered and resolved under.
     *
     * @return  string  Namespaced control identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->controlId;
    }

    /**
     * The declared revision of this control.
     *
     * @return  int  One or higher.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{control_id: string, edits: string, version: int}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'control_id' => $this->controlId,
            'edits' => $this->edits->value,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated field control declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped, or the edited type is
     *          outside the published vocabulary.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['control_id', 'edits', 'version'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition field control declaration must carry exactly its members.',
            );
        }
        $controlId = $data['control_id'];
        $edits = $data['edits'];
        $version = $data['version'];
        if (!is_string($controlId) || !is_string($edits) || !is_int($version)) {
            throw new InvalidArgumentException(
                'A composition field control declaration member has the wrong type.',
            );
        }
        $type = CompositionPropertyType::tryFrom($edits);
        if ($type === null) {
            throw new InvalidArgumentException(
                'A composition field control must edit a published property type.',
            );
        }

        return new self($controlId, $type, $version);
    }
}
