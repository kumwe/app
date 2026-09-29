<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Projection;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;

/**
 * Pins one Content type version to the exact Studio Blueprint that composes entries of that type.
 *
 * The Content definition and Blueprint versions are both immutable coordinates. Moving a type to a
 * different Blueprint therefore creates a successor binding revision instead of changing what a
 * previously read binding meant. This value owns no write use case; authorization, audit, and
 * optimistic persistence remain responsibilities of the application service that will publish it.
 *
 * @since  2.0.0
 */
final readonly class ContentBlueprintBinding
{
    /**
     * Record one exact type-to-Blueprint coordinate.
     *
     * @param SiteContext $site Site whose Content definition and binding are addressed.
     * @param   string                      $contentTypeId       Canonical UUID of the Content type.
     * @param   int                         $contentTypeVersion  Published Content definition version, starting at one.
     * @param   string                      $blueprintId         Stable Studio artifact identifier.
     * @param   string                      $blueprintVersion    Exact semantic Blueprint version.
     * @param   ?string                     $blueprintRevision   Exact host revision when one has been selected.
     * @param   int                         $revision            Optimistic binding revision, starting at one.
     * @param   array<string, string>|null  $fieldIds            Content storage keys to exact authored Studio IDs;
     *          null retains the established mapping for types created outside contextual authoring.
     *
     * @throws  InvalidArgumentException  When any coordinate cannot be represented by the pinned contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SiteContext $site,
        public string $contentTypeId,
        public int $contentTypeVersion,
        public string $blueprintId,
        public string $blueprintVersion,
        public ?string $blueprintRevision,
        public int $revision,
        public ?array $fieldIds = null,
    ) {
        if (preg_match(self::UUID, $contentTypeId) !== 1) {
            throw new InvalidArgumentException('A Studio binding content type ID must be a canonical UUID.');
        }
        if ($contentTypeVersion < 1 || $revision < 1) {
            throw new InvalidArgumentException('Studio binding revisions must be positive integers.');
        }
        if (
            preg_match(self::STABLE_ID, $blueprintId) !== 1
            || in_array($blueprintId, self::FORBIDDEN_IDENTIFIERS, true)
        ) {
            throw new InvalidArgumentException('A Studio binding Blueprint ID is invalid.');
        }
        if (strlen($blueprintVersion) > 100 || preg_match(self::SEMANTIC_VERSION, $blueprintVersion) !== 1) {
            throw new InvalidArgumentException('A Studio binding Blueprint version is not semantic.');
        }
        if ($blueprintRevision !== null && ($blueprintRevision === '' || strlen($blueprintRevision) > 200)) {
            throw new InvalidArgumentException('A Studio binding Blueprint revision is invalid.');
        }
        if ($fieldIds !== null) {
            $seen = ['title' => true, 'slug' => true];
            foreach ($fieldIds as $key => $id) {
                if (
                    !is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1
                    || !is_string($id) || strlen($id) > 100
                    || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $id) !== 1
                    || in_array($id, self::FORBIDDEN_IDENTIFIERS, true) || isset($seen[$id])
                ) {
                    throw new InvalidArgumentException('The Studio Content field identity map is invalid.');
                }
                $seen[$id] = true;
            }
        }
    }

    /**
     * Resolve a Content storage key without changing the field identity the author created.
     *
     * @param   string  $key  Schema-owned Content data key.
     *
     * @return  string  Exact Studio field identifier.
     *
     * @throws  InvalidArgumentException  When an authored map does not cover the requested field.
     *
     * @since   2.0.0
     */
    public function fieldId(string $key): string
    {
        return $this->fieldIds === null
            ? 'data_' . $key
            : ($this->fieldIds[$key] ?? throw new InvalidArgumentException('The Studio field map is incomplete.'));
    }

    /**
     * UUID grammar shared by binding coordinates.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    /**
     * Stable artifact identifier grammar from the pinned Studio common schema.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STABLE_ID = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,239}$/D';

    /**
     * Property names forbidden by every Studio identifier vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FORBIDDEN_IDENTIFIERS = ['__proto__', 'prototype', 'constructor'];

    /**
     * Semantic-version grammar from the pinned Studio common schema.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SEMANTIC_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
        . '(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'
        . '(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?'
        . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';
}
