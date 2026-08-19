<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * An inspector a package declares for one of its own composition blocks.
 *
 * The inspector is the panel the Gate B authoring surface opens beside a selected block: the place its
 * properties are edited as one arrangement rather than field by field. The profile derives a default
 * inspector from the block's bounded property schema, and a package may declare a purpose-built one for
 * a block it owns. Binding is by declared block identifier, checked against the same manifest, so an
 * inspector for a block the package does not declare is refused at admission rather than discovered as
 * an orphan when the surface ships.
 *
 * Nothing executable is declared; the panel's implementation is a Gate B authoring-surface concern.
 *
 * @since  2.0.0
 */
final readonly class CompositionInspectorDeclaration implements ContributionDefinition
{
    /**
     * Declare one inspector and the owned block it inspects.
     *
     * @param   string  $inspectorId  Namespaced identifier inside the declaring package's namespace.
     * @param   string  $block        Namespaced identifier of the declared block this inspector opens for.
     * @param   int     $version      Declared inspector revision, one or higher.
     *
     * @throws  InvalidArgumentException  When either identifier is not namespaced or the version is
     *          not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $inspectorId,
        private string $block,
        private int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $inspectorId) !== 1) {
            throw new InvalidArgumentException('A composition inspector identifier must be namespaced.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $block) !== 1) {
            throw new InvalidArgumentException(
                'A composition inspector block reference must be a namespaced identifier.',
            );
        }
        if ($version < 1) {
            throw new InvalidArgumentException('A composition inspector version must be a positive integer.');
        }
    }

    /**
     * The identifier this inspector is registered and resolved under.
     *
     * @return  string  Namespaced inspector identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->inspectorId;
    }

    /**
     * The declared block this inspector opens for.
     *
     * @return  string  Namespaced block identifier, declared in the same manifest.
     *
     * @since   2.0.0
     */
    public function block(): string
    {
        return $this->block;
    }

    /**
     * The declared revision of this inspector.
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
     * @return  array{inspector_id: string, block: string, version: int}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'inspector_id' => $this->inspectorId,
            'block' => $this->block,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated inspector declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['block', 'inspector_id', 'version'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition inspector declaration must carry exactly its members.',
            );
        }
        $inspectorId = $data['inspector_id'];
        $block = $data['block'];
        $version = $data['version'];
        if (!is_string($inspectorId) || !is_string($block) || !is_int($version)) {
            throw new InvalidArgumentException(
                'A composition inspector declaration member has the wrong type.',
            );
        }

        return new self($inspectorId, $block, $version);
    }
}
