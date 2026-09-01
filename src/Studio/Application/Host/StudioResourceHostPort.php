<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\ResourcePortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Read-only Studio resource port over explicitly composed, policy-aware App providers.
 *
 * @since  2.0.0
 */
final readonly class StudioResourceHostPort implements ResourcePortInterface
{
    /**
     * Largest offset the bounded host resource port can represent without crossing its page ceiling.
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
     * Authorized Producer request scope, when this port has been bound for dispatch.
     *
     * @var    ?StudioProducerRequestAuthority
     * @since  2.0.0
     */
    private ?StudioProducerRequestAuthority $authority;

    /**
     * Index exact provider ownership and reject ambiguous resource families at composition time.
     *
     * @param  iterable<StudioResourceSearchProvider>  $providers  App-owned resource projections.
     * @param  StudioProducerRequestAuthority|null $authority Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(iterable $providers, ?StudioProducerRequestAuthority $authority = null)
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
        $this->authority = $authority;
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped resource port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self(array_values($this->providers), $authority);
    }

    /**
     * Execute canonical resource search without accepting a write context or query language.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical resource page.
     *
     * @since   2.0.0
     */
    public function search(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['query']) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $query = $arguments->query;
        if (!$query instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $offset = self::decodeCursor($cursor);
        $provider = $this->providers[$resourceType] ?? null;
        $page = $provider?->search($authority->context(), $search, $offset, $limit)
            ?? new StudioResourceSearchPage([], false);
        if (count($page->items) > $limit || ($page->hasNext && $page->items === [])) {
            StudioProducerError::refuse('internal', 'studio.resource/provider-invalid');
        }

        $identifiers = [];
        foreach ($page->items as $item) {
            if (isset($identifiers[$item->id])) {
                StudioProducerError::refuse('internal', 'studio.resource/provider-invalid');
            }
            $identifiers[$item->id] = true;
        }
        $nextOffset = $offset + count($page->items);
        if ($page->hasNext && $nextOffset > self::MAX_CURSOR_OFFSET) {
            StudioProducerError::refuse('internal', 'studio.resource/provider-invalid');
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

        return new HostResult($value);
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio resource port requires request authority.');
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
            StudioProducerError::refuse('invalid-request', 'studio.resource/invalid-cursor');
        }
        if (preg_match('/^index:(0|[1-9][0-9]{0,6})$/D', $decoded, $matches) !== 1) {
            StudioProducerError::refuse('invalid-request', 'studio.resource/invalid-cursor');
        }
        $offset = (int) $matches[1];
        if ($offset > self::MAX_CURSOR_OFFSET) {
            StudioProducerError::refuse('invalid-request', 'studio.resource/invalid-cursor');
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
