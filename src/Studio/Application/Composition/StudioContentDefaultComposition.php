<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use stdClass;

/**
 * Derives the layout an authoring session shows for a Content type version that has no authored layout.
 *
 * The layout is one core section holding one App Content-field block per scalar field the projected
 * Content model exposes, bound to that field, in the model's authoring order: the entry title, then the
 * type's own top-level fields. Only block types present in the given lock are composed, so the result is
 * always admissible against it. Objects, collections, enumerations and the slug are left for the author to
 * add. App ADR 0024 records why this default exists and where it is applied.
 *
 * @since  2.0.0
 */
final readonly class StudioContentDefaultComposition
{
    /**
     * Derivation generation mixed into the initial revision of a provisioned default composition.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string VERSION = 'content-default-composition-1';

    /**
     * Node identity of the section that holds the derived field blocks.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SECTION_ID = 'default/section';

    /**
     * Node identity prefix of every derived field block.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FIELD_PREFIX = 'default/field/';

    /**
     * Core structural block type that holds the derived field blocks.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SECTION_TYPE = 'studio.core/section';

    /**
     * Slot of the section that receives the derived field blocks.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SECTION_SLOT = 'content';

    /**
     * Most children the section slot accepts, matching its declared maximum.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int SECTION_MAXIMUM = 100;

    /**
     * Authoring order Studio assumes for a field that declares none.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int UNORDERED = 9007199254740991;

    /**
     * Content-field block type composed for each projected field kind.
     *
     * Each pair is one the pinned Studio binding rule accepts: a field kind equal to the port value type, or
     * a string field on a text port. Enumerations are left out on purpose: a text port would accept them, but
     * the inspector would then offer free text for a closed set.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array FIELD_BLOCKS = [
        'string' => 'core/field-text',
        'integer' => 'core/field-integer',
        'decimal' => 'core/field-decimal',
        'boolean' => 'core/field-boolean',
        'date' => 'core/field-date',
        'date-time' => 'core/field-date-time',
        'media' => 'core/field-media',
    ];

    /**
     * Derive the default roots for one projected Content model under one exact block lock.
     *
     * @param   stdClass        $model       Schema-valid Studio `content-model` projection of the type version.
     * @param   list<stdClass>  $blockLocks  Exact `{type, version, revision}` locks the Blueprint carries.
     *
     * @return  list<stdClass>  One section root holding the bound field blocks, or an empty list when the lock
     *          has no section or the model has no composable field.
     *
     * @since   2.0.0
     */
    public static function roots(stdClass $model, array $blockLocks): array
    {
        $versions = self::lockedVersions($blockLocks);
        $sectionVersion = $versions[self::SECTION_TYPE] ?? null;
        if ($sectionVersion === null) {
            return [];
        }
        $children = [];
        foreach (self::composableFields($model) as [$fieldId, $kind]) {
            $type = self::FIELD_BLOCKS[$kind] ?? null;
            $version = $type === null ? null : ($versions[$type] ?? null);
            if ($type === null || $version === null) {
                continue;
            }
            $children[] = self::fieldNode($fieldId, $type, $version);
            if (count($children) === self::SECTION_MAXIMUM) {
                break;
            }
        }
        if ($children === []) {
            return [];
        }
        $slots = new stdClass();
        $slots->{self::SECTION_SLOT} = $children;

        return [(object) [
            'id' => self::SECTION_ID,
            'type' => self::SECTION_TYPE,
            'version' => $sectionVersion,
            'properties' => new stdClass(),
            'bindings' => new stdClass(),
            'slots' => $slots,
            'authoring' => (object) ['mode' => 'structural'],
        ]];
    }

    /**
     * Return the Blueprint an authoring session is handed for one stored type composition.
     *
     * A stored draft with no roots is answered with a copy carrying the derived default roots; its identity,
     * version, revision and model are unchanged and nothing is persisted. Its stored lock wins for every block
     * type it names. A draft a type save stored with an empty layout locks no block at all, so the default is
     * derived against the deployment's renderable locks as well, and the copy's lock gains exactly the
     * renderable lock of each composed block type the stored lock does not name. Any other document is
     * returned as stored, so an authored layout is never replaced.
     *
     * @param   stdClass        $blueprint        Stored Blueprint document of the type version.
     * @param   stdClass        $model            Content-model projection handed to the session beside it.
     * @param   list<stdClass>  $renderableLocks  Exact `{type, version, revision}` locks the deployment renders,
     *          used only for block types the stored lock does not name.
     *
     * @return  stdClass  The stored document itself, or a copy carrying the derived default roots.
     *
     * @since   2.0.0
     */
    public static function presented(stdClass $blueprint, stdClass $model, array $renderableLocks): stdClass
    {
        if (($blueprint->status ?? null) !== 'draft' || ($blueprint->roots ?? null) !== []) {
            return $blueprint;
        }
        $stored = self::blockLocks($blueprint);
        $roots = self::roots($model, [...$stored, ...$renderableLocks]);
        if ($roots === []) {
            return $blueprint;
        }
        $presented = clone $blueprint;
        $presented->roots = $roots;
        $added = self::missingLocks($stored, $renderableLocks, $roots);
        if ($added !== []) {
            $lock = $blueprint->dependencyLock ?? null;
            $lock = $lock instanceof stdClass ? clone $lock : new stdClass();
            $lock->blocks = [...$stored, ...$added];
            $presented->dependencyLock = $lock;
        }

        return $presented;
    }

    /**
     * Node identity of the field block bound to one projected field.
     *
     * Field identifiers may contain `_`, which a Blueprint node identity may not; `:` never occurs in a field
     * identifier, so mapping `_` to `:` keeps distinct fields distinct.
     *
     * @param   string  $fieldId  Projected Content-model field identifier.
     *
     * @return  string  Stable node identity under `default/field/`.
     *
     * @since   2.0.0
     */
    public static function fieldNodeId(string $fieldId): string
    {
        return self::FIELD_PREFIX . strtr($fieldId, '_', ':');
    }

    /**
     * Decide whether a type save carries the derived default layout exactly as the session was handed it.
     *
     * A type save stores an untouched derived default as an empty draft, re-derived on every load (App ADR
     * 0024); an authored draft layout, including one kept as a draft on the composition screen, publishes.
     *
     * @param   stdClass      $handed         Blueprint the session was handed.
     * @param   stdClass      $model          Content-model projection handed beside it.
     * @param   array<mixed>  $authoredRoots  Roots the save request carries.
     *
     * @return  bool  True only for a draft whose non-empty handed roots equal both the authored roots and the
     *          default derived from the model and the handed lock.
     *
     * @since   2.0.0
     */
    public static function untouched(stdClass $handed, stdClass $model, array $authoredRoots): bool
    {
        $handedRoots = $handed->roots ?? null;
        if (($handed->status ?? null) !== 'draft' || !is_array($handedRoots) || $handedRoots === []) {
            return false;
        }
        try {
            $canonical = CanonicalJson::stringify($handedRoots);
            $derived = CanonicalJson::stringify(self::roots($model, self::blockLocks($handed)));

            return hash_equals($canonical, CanonicalJson::stringify($authoredRoots))
                && hash_equals($canonical, $derived);
        } catch (CanonicalEncodingException) {
            return false;
        }
    }

    /**
     * Read the block locks a Blueprint document carries, keeping only object entries.
     *
     * @param   stdClass  $blueprint  Blueprint document.
     *
     * @return  list<stdClass>  Lock entries in document order.
     *
     * @since   2.0.0
     */
    private static function blockLocks(stdClass $blueprint): array
    {
        $lock = $blueprint->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        if (!is_array($blocks)) {
            return [];
        }

        $locks = [];
        foreach ($blocks as $block) {
            if ($block instanceof stdClass) {
                $locks[] = $block;
            }
        }

        return $locks;
    }

    /**
     * Select the renderable locks of the composed block types a stored lock does not name.
     *
     * @param   list<stdClass>  $stored           Locks the stored Blueprint carries.
     * @param   list<stdClass>  $renderableLocks  Locks the deployment renders, in deployment order.
     * @param   list<stdClass>  $roots            Derived default roots.
     *
     * @return  list<stdClass>  One renderable lock per composed type the stored lock lacks, in deployment order.
     *
     * @since   2.0.0
     */
    private static function missingLocks(array $stored, array $renderableLocks, array $roots): array
    {
        $composed = [];
        foreach ($roots as $root) {
            $nodes = [$root];
            $slots = $root->slots ?? null;
            foreach ($slots instanceof stdClass ? get_object_vars($slots) : [] as $children) {
                foreach (is_array($children) ? $children : [] as $child) {
                    $nodes[] = $child;
                }
            }
            foreach ($nodes as $node) {
                $type = $node instanceof stdClass ? ($node->type ?? null) : null;
                if (is_string($type)) {
                    $composed[$type] = true;
                }
            }
        }
        $named = self::lockedVersions($stored);
        $missing = [];
        foreach ($renderableLocks as $lock) {
            $type = $lock->type ?? null;
            if (is_string($type) && isset($composed[$type]) && !isset($named[$type])) {
                $missing[] = $lock;
                $named[$type] = '';
            }
        }

        return $missing;
    }

    /**
     * Index the exact locked version of every block type a lock names.
     *
     * @param   list<stdClass>  $blockLocks  Exact `{type, version, revision}` locks.
     *
     * @return  array<string, string>  Locked version keyed by block type; the first lock of a type wins.
     *
     * @since   2.0.0
     */
    private static function lockedVersions(array $blockLocks): array
    {
        $versions = [];
        foreach ($blockLocks as $lock) {
            $type = $lock->type ?? null;
            $version = $lock->version ?? null;
            if (is_string($type) && is_string($version) && $version !== '' && !isset($versions[$type])) {
                $versions[$type] = $version;
            }
        }

        return $versions;
    }

    /**
     * List the projected fields a default composes, in the model's authoring order.
     *
     * A field is composable when it is the entry title or a top-level Content data field, has cardinality
     * one, and is not hidden from authoring. Fields are ordered by their authoring order, then by their
     * position in the model, which is the order Studio lists binding candidates in.
     *
     * @param   stdClass  $model  Content-model projection.
     *
     * @return  list<array{0: string, 1: string}>  Field identifier and projected kind of each composable field.
     *
     * @since   2.0.0
     */
    private static function composableFields(stdClass $model): array
    {
        $fields = $model->fields ?? null;
        if (!is_array($fields)) {
            return [];
        }
        $byOrder = [];
        foreach ($fields as $field) {
            if (!$field instanceof stdClass) {
                continue;
            }
            $id = $field->id ?? null;
            $kind = $field->kind ?? null;
            $authoring = $field->authoring ?? null;
            $order = $authoring instanceof stdClass ? ($authoring->order ?? null) : null;
            if (
                !is_string($id)
                || $id === ''
                || !is_string($kind)
                || ($field->cardinality ?? null) !== 'one'
                || ($authoring instanceof stdClass && ($authoring->hidden ?? null) === true)
                || !self::isComposableSource($field)
            ) {
                continue;
            }
            // Fields that share an order keep their model position, because each group fills in model order.
            $byOrder[is_int($order) ? $order : self::UNORDERED][] = [$id, $kind];
        }
        ksort($byOrder, SORT_NUMERIC);
        $composable = [];
        foreach ($byOrder as $group) {
            foreach ($group as $candidate) {
                $composable[] = $candidate;
            }
        }

        return $composable;
    }

    /**
     * Decide whether a projected field is sourced from the entry title or a top-level Content data field.
     *
     * @param   stdClass  $field  Projected Content-model field.
     *
     * @return  bool  True for the entry title and for top-level Content data fields.
     *
     * @since   2.0.0
     */
    private static function isComposableSource(stdClass $field): bool
    {
        $extensions = $field->extensions ?? null;
        $source = $extensions instanceof stdClass ? ($extensions->{'kumwe.app/source-field'} ?? null) : null;
        if (!$source instanceof stdClass) {
            return false;
        }
        $storage = $source->storage ?? null;

        return $storage === 'data' || ($storage === 'entry' && ($source->key ?? null) === 'title');
    }

    /**
     * Build one Content-field block bound to one projected field.
     *
     * The binding policy is the one Studio writes when an author binds a port to a field by hand.
     *
     * @param   string  $fieldId  Projected Content-model field identifier.
     * @param   string  $type     Locked Content-field block type.
     * @param   string  $version  Locked block version.
     *
     * @return  stdClass  Blueprint node in content authoring mode.
     *
     * @since   2.0.0
     */
    private static function fieldNode(string $fieldId, string $type, string $version): stdClass
    {
        return (object) [
            'id' => self::fieldNodeId($fieldId),
            'type' => $type,
            'version' => $version,
            'properties' => new stdClass(),
            'bindings' => (object) [
                'value' => (object) [
                    'source' => (object) ['kind' => 'entry-field', 'fieldPath' => [$fieldId]],
                    'transforms' => [],
                    'onNull' => 'empty',
                    'onError' => 'error',
                ],
            ],
            'slots' => new stdClass(),
            'authoring' => (object) ['mode' => 'content'],
        ];
    }
}
