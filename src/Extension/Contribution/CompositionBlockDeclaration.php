<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * What a package declares before any of its blocks may appear in a composed document.
 *
 * A block is the unit an author places on a canvas: a bounded property schema for what its instances
 * carry, the named slots other blocks may be nested into, and the renderer binding the Gate B surface
 * resolves when the document is rendered. All three are declared here, in the signed manifest, because
 * a composition document outlives the code that produced it — the contract has to be inspectable before
 * install and stable afterwards, exactly as decision D16 requires. Nothing here renders or stores: the
 * declaration is inert until the composition surface ships, and an extension declaring one today
 * installs unchanged when it does.
 *
 * The renderer binding is an owner-namespaced reference rather than a class name or a template path,
 * so the declaration promises nothing about implementation shape; a binding that names nothing is
 * refused at admission because an unresolvable block would otherwise surface as a runtime hole.
 *
 * @since  2.0.0
 */
final readonly class CompositionBlockDeclaration implements ContributionDefinition
{
    /**
     * Slots one block may declare, which bounds nesting an author can reach from one placement.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_SLOTS = 16;

    /**
     * Declared slot names, sorted so two orderings declare the same block.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $slots;

    /**
     * Declare one block, its bounded properties, its slots, and its renderer binding.
     *
     * @param   string                     $blockId     Namespaced identifier inside the declaring
     *          package's namespace.
     * @param   CompositionPropertySchema  $properties  Bounded property schema its instances carry.
     * @param   list<string>               $slots       Named positions other blocks may be nested into.
     * @param   string                     $renderer    Owner-namespaced renderer binding resolved at Gate B.
     * @param   int                        $version     Declared block revision, one or higher.
     *
     * @throws  InvalidArgumentException  When the identifier or renderer is not namespaced, a slot name is
     *          malformed or repeated, the slot list is over its bound, or the version is not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $blockId,
        public CompositionPropertySchema $properties,
        array $slots,
        private string $renderer,
        private int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $blockId) !== 1) {
            throw new InvalidArgumentException('A composition block identifier must be namespaced.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $renderer) !== 1) {
            throw new InvalidArgumentException(
                'A composition block renderer binding must be a namespaced reference.',
            );
        }
        if (count($slots) > self::MAXIMUM_SLOTS) {
            throw new InvalidArgumentException('A composition block must declare at most 16 slots.');
        }
        foreach ($slots as $slot) {
            if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $slot) !== 1 || strlen($slot) > 64) {
                throw new InvalidArgumentException(
                    'A composition block slot name must be a bounded lowercase identifier.',
                );
            }
        }
        if ($slots !== array_unique($slots)) {
            throw new InvalidArgumentException('A composition block repeats a slot name.');
        }
        sort($slots, SORT_STRING);
        $this->slots = $slots;
        if ($version < 1) {
            throw new InvalidArgumentException('A composition block version must be a positive integer.');
        }
    }

    /**
     * The identifier this block is registered, referenced, and migrated under.
     *
     * @return  string  Namespaced block identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->blockId;
    }

    /**
     * The owner-namespaced renderer binding this block resolves through when the surface ships.
     *
     * @return  string  Namespaced renderer reference; never a class name or a template path.
     *
     * @since   2.0.0
     */
    public function renderer(): string
    {
        return $this->renderer;
    }

    /**
     * The declared revision of this block's schema, which its migrations step between.
     *
     * @return  int  One or higher; a migration may never target a version past this.
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
     * @return  array{
     *              block_id: string,
     *              properties: array<string, array<string, mixed>>,
     *              slots: list<string>,
     *              renderer: string,
     *              version: int
     *          }  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'block_id' => $this->blockId,
            'properties' => $this->properties->toArray(),
            'slots' => $this->slots,
            'renderer' => $this->renderer,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated composition block declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped, or the embedded
     *          property schema fails the published profile.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['block_id', 'properties', 'renderer', 'slots', 'version'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition block declaration must carry exactly its members.',
            );
        }
        $blockId = $data['block_id'];
        $properties = $data['properties'];
        $slots = $data['slots'];
        $renderer = $data['renderer'];
        $version = $data['version'];
        if (
            !is_string($blockId)
            || !is_array($properties)
            || (array_is_list($properties) && $properties !== [])
            || !is_array($slots)
            || !array_is_list($slots)
            || !is_string($renderer)
            || !is_int($version)
        ) {
            throw new InvalidArgumentException('A composition block declaration member has the wrong type.');
        }
        $names = [];
        foreach ($slots as $slot) {
            if (!is_string($slot)) {
                throw new InvalidArgumentException('A composition block slot must be a string.');
            }
            $names[] = $slot;
        }
        /** @var array<string, mixed> $properties */

        return new self($blockId, CompositionPropertySchema::fromArray($properties), $names, $renderer, $version);
    }
}
