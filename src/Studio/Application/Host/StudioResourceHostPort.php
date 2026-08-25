<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use stdClass;

/**
 * Read-only Studio resource port over explicitly composed, policy-aware App providers.
 *
 * @since  2.0.0
 */
final readonly class StudioResourceHostPort
{
    /**
     * Largest offset the bounded host-resource bridge can represent without crossing its page ceiling.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_CURSOR_OFFSET = 4_999_900;

    /**
     * Policy-aware providers indexed by their exact portable resource type.
     *
     * @var    array<string, StudioResourceSearchProvider>
     * @since  2.0.0
     */
    private array $providers;

    /**
     * Index exact provider ownership and reject ambiguous resource families at composition time.
     *
     * @param  iterable<StudioResourceSearchProvider>  $providers  App-owned resource projections.
     *
     * @since  2.0.0
     */
    public function __construct(iterable $providers)
    {
        $indexed = [];
        foreach ($providers as $provider) {
            $type = $provider->resourceType();
            if (!self::qualifiedName($type) || isset($indexed[$type])) {
                throw new InvalidArgumentException('A Studio resource search provider is invalid or duplicated.');
            }
            $indexed[$type] = $provider;
        }
        ksort($indexed, SORT_STRING);
        $this->providers = $indexed;
    }

    /**
     * Dispatch the canonical resource search without accepting a write context or query language.
     *
     * @param   ExecutionContext           $context    Trusted actor and site.
     * @param   string                     $operation  Canonical resource operation.
     * @param   StudioHostRequest          $request    Validated host envelope.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session snapshot.
     *
     * @return  StudioHostResult  Canonical resource page.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        unset($snapshot);
        if ($operation !== 'search') {
            throw new StudioHostOperationRefused('incompatible', 'studio.host/operation-unavailable');
        }
        if ($request->expectedRevision !== null || $request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
        $arguments = $request->arguments;
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['query']) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $query = $arguments->query;
        if (!$query instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = self::members($query);
        if (
            !in_array($members, [
            ['limit', 'resourceType'],
            ['cursor', 'limit', 'resourceType'],
            ['limit', 'resourceType', 'search'],
            ['cursor', 'limit', 'resourceType', 'search'],
            ], true)
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $limit = $query->limit ?? null;
        $resourceType = $query->resourceType ?? null;
        $search = $query->search ?? '';
        $cursor = $query->cursor ?? null;
        if (
            !is_int($limit)
            || $limit < 1
            || $limit > 100
            || !is_string($resourceType)
            || !self::qualifiedName($resourceType)
            || !is_string($search)
            || mb_strlen($search) > 160
            || ($cursor !== null && !is_string($cursor))
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $offset = self::decodeCursor($cursor);
        $provider = $this->providers[$resourceType] ?? null;
        $page = $provider?->search($context, $search, $offset, $limit)
            ?? new StudioResourceSearchPage([], false);
        if (count($page->items) > $limit || ($page->hasNext && $page->items === [])) {
            throw new StudioHostOperationRefused('internal', 'studio.resource/provider-invalid');
        }

        $identifiers = [];
        foreach ($page->items as $item) {
            if (isset($identifiers[$item->id])) {
                throw new StudioHostOperationRefused('internal', 'studio.resource/provider-invalid');
            }
            $identifiers[$item->id] = true;
        }
        $nextOffset = $offset + count($page->items);
        if ($page->hasNext && $nextOffset > self::MAX_CURSOR_OFFSET) {
            throw new StudioHostOperationRefused('internal', 'studio.resource/provider-invalid');
        }

        $value = new stdClass();
        $value->items = array_map(
            static fn (StudioResourceSearchItem $item): stdClass => (object) [
                'id' => $item->id,
                'label' => (object) [
                    'key' => 'kumwe.app/resource-label',
                    'defaultMessage' => $item->label,
                ],
                'resourceType' => $resourceType,
            ],
            $page->items,
        );
        if ($page->hasNext) {
            $value->nextCursor = self::encodeCursor($nextOffset);
        }

        return new StudioHostResult($value);
    }

    /**
     * Decode one canonical opaque result offset.
     *
     * @param   string|null  $cursor  Optional protocol cursor.
     *
     * @return  int  Zero-based result offset.
     *
     * @since   2.0.0
     */
    private static function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        $decoded = base64_decode($cursor, true);
        if (!is_string($decoded) || !hash_equals(self::encodeCursorText($decoded), $cursor)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.resource/invalid-cursor');
        }
        if (preg_match('/^index:(0|[1-9][0-9]{0,6})$/D', $decoded, $matches) !== 1) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.resource/invalid-cursor');
        }
        $offset = (int) $matches[1];
        if ($offset > self::MAX_CURSOR_OFFSET) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.resource/invalid-cursor');
        }

        return $offset;
    }

    /**
     * Encode one result offset without exposing database coordinates.
     *
     * @param   int  $offset  Zero-based result offset.
     *
     * @return  string  Canonical opaque cursor.
     *
     * @since   2.0.0
     */
    private static function encodeCursor(int $offset): string
    {
        return self::encodeCursorText('index:' . $offset);
    }

    /**
     * Encode one cursor payload as canonical base64 bytes.
     *
     * @param   string  $text  Validated cursor payload.
     *
     * @return  string  Canonical base64 bytes.
     *
     * @since   2.0.0
     */
    private static function encodeCursorText(string $text): string
    {
        return base64_encode($text);
    }

    /**
     * Return deterministic object member names for exact protocol validation.
     *
     * @param   stdClass  $document  Candidate protocol object.
     *
     * @return  list<string>  Deterministically sorted member names.
     *
     * @since   2.0.0
     */
    private static function members(stdClass $document): array
    {
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);

        return $members;
    }

    /**
     * Check the exact Studio qualified-name grammar without accepting aliases.
     *
     * @param   string  $value  Candidate qualified name.
     *
     * @return  bool  Whether the value satisfies the protocol grammar and ceiling.
     *
     * @since   2.0.0
     */
    private static function qualifiedName(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match(
                '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D',
                $value,
            ) === 1;
    }
}
