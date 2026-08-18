<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

/**
 * A reusable composition structure a package declares, built only from blocks it also declares.
 *
 * A pattern is what an author reaches for instead of assembling the same blocks by hand: a declared
 * arrangement the Gate B surface offers as one placement. At Gate A the declaration carries the
 * arrangement as an ordered, bounded list of block references, because that is the part an extension
 * author must be able to depend on — which blocks the pattern is made of, in which order. The full
 * document body a placement expands into is a Gate B artifact produced by the runtime from these same
 * blocks, so it is deliberately not declared here.
 *
 * The reference list is ordered and may repeat a block, because a structure legitimately uses the same
 * block twice; it is bounded, and every reference must name a block declared in the same manifest, so a
 * pattern can never smuggle in a dependency on structure its own package does not promise.
 *
 * @since  2.0.0
 */
final readonly class CompositionPatternDeclaration implements ContributionDefinition
{
    /**
     * Block references one pattern may hold, which bounds what a single placement can expand into.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_BLOCKS = 32;

    /**
     * Ordered block references this pattern is assembled from, repeats permitted.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $blocks;

    /**
     * Declare one pattern and the ordered blocks it arranges.
     *
     * @param   string        $patternId  Namespaced identifier inside the declaring package's namespace.
     * @param   list<string>  $blocks     Namespaced block identifiers in arrangement order.
     * @param   int           $version    Declared pattern revision, one or higher.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced, the block list is empty or
     *          over its bound, a reference is not a namespaced identifier, or the version is not positive.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $patternId,
        array $blocks,
        private int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $patternId) !== 1) {
            throw new InvalidArgumentException('A composition pattern identifier must be namespaced.');
        }
        if ($blocks === [] || count($blocks) > self::MAXIMUM_BLOCKS) {
            throw new InvalidArgumentException(
                'A composition pattern must arrange between one and 32 block references.',
            );
        }
        foreach ($blocks as $block) {
            if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $block) !== 1) {
                throw new InvalidArgumentException(
                    'A composition pattern block reference must be a namespaced identifier.',
                );
            }
        }
        $this->blocks = array_values($blocks);
        if ($version < 1) {
            throw new InvalidArgumentException('A composition pattern version must be a positive integer.');
        }
    }

    /**
     * The identifier this pattern is registered and offered under.
     *
     * @return  string  Namespaced pattern identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->patternId;
    }

    /**
     * The declared revision of this pattern's arrangement.
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
     * @return  array{pattern_id: string, blocks: non-empty-list<string>, version: int}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'pattern_id' => $this->patternId,
            'blocks' => $this->blocks,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated composition pattern declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['blocks', 'pattern_id', 'version'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition pattern declaration must carry exactly its members.',
            );
        }
        $patternId = $data['pattern_id'];
        $blocks = $data['blocks'];
        $version = $data['version'];
        if (!is_string($patternId) || !is_array($blocks) || !array_is_list($blocks) || !is_int($version)) {
            throw new InvalidArgumentException('A composition pattern declaration member has the wrong type.');
        }
        $references = [];
        foreach ($blocks as $block) {
            if (!is_string($block)) {
                throw new InvalidArgumentException('A composition pattern block reference must be a string.');
            }
            $references[] = $block;
        }

        return new self($patternId, $references, $version);
    }
}
