<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Projection;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use stdClass;

/**
 * Canonical per-entry values that override reusable composition decisions without changing Content.
 *
 * The object is copied into Studio's `entry.compositionOverrides` member and is deliberately separate
 * from the Content entry body: Studio may read it, while Content validation, workflow, and translation
 * state remain authoritative in their own bounded context. Canonical bytes are stored privately so a
 * caller cannot mutate an object after construction and change what this value means. The same record
 * may also pin the exact revision of the entry's own item layout, a Blueprint stored as an immutable
 * Studio artifact; without that pointer the entry follows its content type's layout.
 *
 * @since  2.0.0
 */
final readonly class EntryCompositionOverrides
{
    /**
     * Canonical immutable representation of the override object.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $canonical;

    /**
     * Capture one entry's override object at an optimistic revision.
     *
     * @param   SiteContext  $site                   Site whose entry is addressed.
     * @param   string       $entryId                Canonical UUID of the Content entry.
     * @param   stdClass     $values                 Override values keyed by Studio stable node or binding identifier.
     * @param   int          $revision               Optimistic override revision, starting at one.
     * @param   ?string      $itemBlueprintRevision  Pinned item layout revision, or null to follow the type.
     *
     * @throws  InvalidArgumentException  When the entry, revision, key set, pointer, or byte budget is invalid.
     * @throws  CanonicalEncodingException  When a value is not representable as canonical JSON.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SiteContext $site,
        public string $entryId,
        stdClass $values,
        public int $revision,
        public ?string $itemBlueprintRevision = null,
    ) {
        if (preg_match(self::UUID, $entryId) !== 1) {
            throw new InvalidArgumentException('Studio entry overrides require a canonical Content UUID.');
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('A Studio entry override revision must be positive.');
        }
        if ($itemBlueprintRevision !== null && preg_match(self::ITEM_REVISION, $itemBlueprintRevision) !== 1) {
            throw new InvalidArgumentException('A Studio item layout revision must be a content-addressed digest.');
        }
        $members = get_object_vars($values);
        if (count($members) > 1000) {
            throw new InvalidArgumentException('Studio entry overrides may carry at most 1000 members.');
        }
        foreach (array_keys($members) as $name) {
            if (
                preg_match(self::STABLE_ID, (string) $name) !== 1
                || in_array($name, self::FORBIDDEN_IDENTIFIERS, true)
            ) {
                throw new InvalidArgumentException('A Studio entry override key is not a stable identifier.');
            }
        }
        foreach ($members as $value) {
            self::assertJsonValueShape($value, 1);
        }
        $canonical = CanonicalJson::stringify($values);
        if (strlen($canonical) > 1_048_576) {
            throw new InvalidArgumentException('Studio entry overrides exceed the one-megabyte bound.');
        }
        $this->canonical = $canonical;
    }

    /**
     * Return a fresh decoded copy safe for insertion into a projection document.
     *
     * @return  stdClass  The same canonical object supplied at construction.
     *
     * @throws  \JsonException  If an impossible corruption made the private canonical bytes unreadable.
     *
     * @since   2.0.0
     */
    public function values(): stdClass
    {
        $decoded = json_decode($this->canonical, false, 65, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new \LogicException('Canonical Studio entry overrides did not decode to an object.');
        }

        return $decoded;
    }

    /**
     * Return the byte-stable form persistence writes.
     *
     * @return  string  Canonical UTF-8 JSON object bytes.
     *
     * @since   2.0.0
     */
    public function canonical(): string
    {
        return $this->canonical;
    }

    /**
     * Return the artifact identifier of this entry's pinned item layout.
     *
     * Item layouts have one Blueprint artifact per entry for the entry's life; the identifier is
     * derived from the entry UUID, so persistence stores only the pinned revision.
     *
     * @return  ?string  Item Blueprint identifier, or null when the entry follows its content type's layout.
     *
     * @since   2.0.0
     */
    public function itemBlueprintId(): ?string
    {
        if ($this->itemBlueprintRevision === null) {
            return null;
        }

        return self::ITEM_BLUEPRINT_PREFIX . strtolower($this->entryId);
    }

    /**
     * Enforce the recursive limits inherited from Studio's canonical JSON value definition.
     *
     * @param   mixed  $value  Candidate nested override value.
     * @param   int    $depth  Number of containers already entered, including the root override object.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a list, object, member name, or depth exceeds the pinned contract.
     *
     * @since   2.0.0
     */
    private static function assertJsonValueShape(mixed $value, int $depth): void
    {
        if (!is_array($value) && !$value instanceof stdClass) {
            return;
        }
        if ($depth >= CanonicalJson::DEFAULT_MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Studio entry overrides exceed the canonical depth bound.');
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return;
            }
            if (count($value) > 10_000) {
                throw new InvalidArgumentException('A Studio entry override list may carry at most 10000 items.');
            }
            foreach ($value as $member) {
                self::assertJsonValueShape($member, $depth + 1);
            }

            return;
        }

        $members = get_object_vars($value);
        if (count($members) > 10_000) {
            throw new InvalidArgumentException('A Studio entry override object may carry at most 10000 members.');
        }
        foreach ($members as $name => $member) {
            if (
                mb_strlen($name, 'UTF-8') < 1
                || mb_strlen($name, 'UTF-8') > 200
                || preg_match('/^[^\x00-\x1F\x7F]+$/uD', $name) !== 1
            ) {
                throw new InvalidArgumentException('A nested Studio entry override member name is invalid.');
            }
            self::assertJsonValueShape($member, $depth + 1);
        }
    }

    /**
     * Identifier prefix of every item layout Blueprint, disjoint from the type Blueprint prefix.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ITEM_BLUEPRINT_PREFIX = 'content-item-blueprint:';

    /**
     * Content-addressed item layout revision grammar: a fixed prefix and a lowercase SHA-256 digest.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ITEM_REVISION = '/^item-[0-9a-f]{64}$/D';

    /**
     * Canonical UUID grammar shared with Content entries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    /**
     * Stable identifier grammar from Studio's common schema.
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
}
