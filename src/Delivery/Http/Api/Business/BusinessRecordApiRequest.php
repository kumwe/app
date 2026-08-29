<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use InvalidArgumentException;
use JsonException;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey as ApplicationIdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey as HttpIdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * Validates the transport grammar shared by the generic business-record REST routes.
 *
 * This class performs shape work only: bounded JSON decoding, closed body keys, parsed concurrency and
 * idempotency attributes, route text and query-string scalar conversion. Definition-aware validation,
 * authorization and value normalization remain in `BusinessRecordService` and the existing command/query
 * constructors. No organization member is accepted anywhere; the handler takes that scope only from the
 * authenticated `ExecutionContext`.
 *
 * @since  2.0.0
 */
final class BusinessRecordApiRequest
{
    /**
     * Maximum bytes decoded from one business-record request body.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_BODY_BYTES = 1_048_576;

    /**
     * Maximum decoded value nodes admitted before command-level field bounds are applied.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_DOCUMENT_NODES = 8_192;

    /**
     * Read a non-empty route attribute.
     *
     * @param   ServerRequestInterface  $request  Matched request carrying router attributes.
     * @param   string                  $name     Attribute name declared by the route.
     * @param   string                  $kind     Resource name used in a safe validation message.
     *
     * @return  string  Attribute text exactly as the router decoded it.
     *
     * @throws  InvalidArgumentException  When the attribute is absent, non-string or empty.
     *
     * @since   2.0.0
     */
    public static function route(ServerRequestInterface $request, string $name, string $kind): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('The business-record ' . $kind . ' route attribute is missing.');
        }

        return $value;
    }

    /**
     * Decode a bounded JSON object body, retaining nested object/list distinction until a member is read.
     *
     * A declared stream size is checked before materialization and the actual string length afterwards, so
     * both sized and chunked request bodies meet the same cap. Empty bodies are accepted only for operations
     * that explicitly pass `$allowEmpty`; a JSON list is never treated as a named request object.
     *
     * @param   ServerRequestInterface  $request     Request whose body is decoded.
     * @param   bool                    $allowEmpty  Whether no bytes means the empty request object.
     *
     * @return  array<string, mixed>  Top-level members; nested JSON objects remain `stdClass` instances.
     *
     * @throws  InvalidArgumentException  When oversized, malformed, too deep, empty when required or not an object.
     *
     * @since   2.0.0
     */
    public static function json(ServerRequestInterface $request, bool $allowEmpty = false): array
    {
        $size = $request->getBody()->getSize();
        if ($size !== null && $size > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('The business-record request body exceeds 1048576 bytes.');
        }
        $json = (string) $request->getBody();
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('The business-record request body exceeds 1048576 bytes.');
        }
        if (trim($json) === '') {
            if ($allowEmpty) {
                return [];
            }
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        try {
            $decoded = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid bounded JSON.', 0, $exception);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $members */
        $members = get_object_vars($decoded);

        return $members;
    }

    /**
     * Normalize a decoded body into the transport-neutral arrays used by application request factories.
     *
     * @param   array<string, mixed>  $document  Top-level members returned by `json()`.
     *
     * @return  array<string, mixed>  Nested objects converted to maps and JSON lists retained as lists.
     *
     * @throws  InvalidArgumentException  When the structure exceeds the shared depth or node budget.
     *
     * @since   2.0.0
     */
    public static function normalize(array $document): array
    {
        $nodes = 0;
        $normalized = [];
        foreach ($document as $key => $value) {
            $normalized[$key] = self::normalizeValue($value, 0, $nodes);
        }

        return $normalized;
    }

    /**
     * Read a required JSON object member and normalize it to an associative array.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Required object member.
     *
     * @return  array<string, mixed>  Normalized object members.
     *
     * @throws  InvalidArgumentException  When absent or represented by a JSON scalar or list.
     *
     * @since   2.0.0
     */
    public static function object(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException('The ' . $key . ' field must be a JSON object.');
        }

        /** @var array<string, mixed> $members */
        $members = get_object_vars($value);

        return self::normalize($members);
    }

    /**
     * Read an optional JSON object member, returning an empty map when absent.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Optional object member.
     *
     * @return  array<string, mixed>  Normalized object, or the empty map when omitted.
     *
     * @throws  InvalidArgumentException  When present but not a JSON object.
     *
     * @since   2.0.0
     */
    public static function optionalObject(array $document, string $key): array
    {
        return array_key_exists($key, $document) ? self::object($document, $key) : [];
    }

    /**
     * Read a required non-empty string body member without repairing its spelling.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Required string member.
     *
     * @return  string  Supplied string unchanged.
     *
     * @throws  InvalidArgumentException  When absent, non-string or empty.
     *
     * @since   2.0.0
     */
    public static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('The ' . $key . ' field must be a non-empty string.');
        }

        return $value;
    }

    /**
     * Read an optional string-or-null body member.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Optional string member.
     *
     * @return  string|null  Supplied string, or null when omitted or explicitly null.
     *
     * @throws  InvalidArgumentException  When present as another JSON type or as an empty string.
     *
     * @since   2.0.0
     */
    public static function optionalString(array $document, string $key): ?string
    {
        if (!array_key_exists($key, $document) || $document[$key] === null) {
            return null;
        }

        return self::string($document, $key);
    }

    /**
     * Read an optional integer body member.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Optional integer member.
     *
     * @return  int|null  Supplied integer or null when absent or explicitly null.
     *
     * @throws  InvalidArgumentException  When present as a non-integer JSON number or another type.
     *
     * @since   2.0.0
     */
    public static function optionalInteger(array $document, string $key): ?int
    {
        if (!array_key_exists($key, $document) || $document[$key] === null) {
            return null;
        }
        if (!is_int($document[$key])) {
            throw new InvalidArgumentException('The ' . $key . ' field must be an integer or null.');
        }

        return $document[$key];
    }

    /**
     * Read a required list containing non-empty strings only.
     *
     * @param   array<string, mixed>  $document  Decoded body members.
     * @param   string                $key       Required list member.
     *
     * @return  list<string>  Supplied string list, re-indexed.
     *
     * @throws  InvalidArgumentException  When absent, not a JSON list, oversized or containing invalid members.
     *
     * @since   2.0.0
     */
    public static function stringList(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > 1000) {
            throw new InvalidArgumentException('The ' . $key . ' field must be a bounded JSON string list.');
        }
        $strings = [];
        foreach ($value as $member) {
            if (!is_string($member) || $member === '') {
                throw new InvalidArgumentException('The ' . $key . ' field must contain non-empty strings.');
            }
            $strings[] = $member;
        }

        return $strings;
    }

    /**
     * Refuse every body or query key an operation did not explicitly declare.
     *
     * @param   array<string, mixed>  $document  Object whose member names are inspected.
     * @param   list<string>          $allowed   Complete operation allow-list.
     * @param   string                $kind      Safe production name for the rejection.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an unknown member is present.
     *
     * @since   2.0.0
     */
    public static function keys(array $document, array $allowed, string $kind): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidArgumentException('The business-record ' . $kind . ' contains an unknown field.');
        }
    }

    /**
     * Normalize query-string scalar booleans and integers into the shared query factory's exact types.
     *
     * Nested bracket notation remains nested arrays. Only grammar positions declared boolean or integer
     * are converted; filter literals stay strings, which prevents a query parser from guessing whether an
     * exact value was meant as a number. Complex typed searches should use the JSON search resource.
     *
     * @param   ServerRequestInterface  $request  Request carrying parsed query parameters.
     *
     * @return  array<string, mixed>  Query document accepted by `BusinessRecordQueryFactory`.
     *
     * @throws  InvalidArgumentException  When nesting, node count or a converted scalar is invalid.
     *
     * @since   2.0.0
     */
    public static function query(ServerRequestInterface $request): array
    {
        $nodes = 0;
        /** @var array<string, mixed> $result */
        $result = self::normalizeQueryMap($request->getQueryParams(), 0, $nodes);
        self::assertFilterBudget($result['filter'] ?? null);

        return $result;
    }

    /**
     * Normalize a JSON search document and apply the same early filter budget as a query string.
     *
     * @param   array<string, mixed>  $document  Top-level members returned by `json()`.
     *
     * @return  array<string, mixed>  Transport-neutral query document for the shared query factory.
     *
     * @throws  InvalidArgumentException  When document or filter structure exceeds its safe bounds.
     *
     * @since   2.0.0
     */
    public static function queryDocument(array $document): array
    {
        $result = self::normalize($document);
        self::assertFilterBudget($result['filter'] ?? null);

        return $result;
    }

    /**
     * Require the parsed idempotency attribute installed by `RequireIdempotencyKeyMiddleware`.
     *
     * The raw header is never reparsed here. A route that omits the middleware fails closed instead of
     * accepting a mutation under a second parser, and the application key receives the already-normalized
     * transport value verbatim.
     *
     * @param   ServerRequestInterface  $request  Mutation request after idempotency middleware.
     *
     * @return  ApplicationIdempotencyKey  Key type carried by business-record commands.
     *
     * @throws  InvalidArgumentException  When the parsed attribute is absent.
     *
     * @since   2.0.0
     */
    public static function idempotencyKey(ServerRequestInterface $request): ApplicationIdempotencyKey
    {
        $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
        if (!$key instanceof HttpIdempotencyKey) {
            throw new InvalidArgumentException('A parsed Idempotency-Key is required for this mutation.');
        }

        return ApplicationIdempotencyKey::fromString((string) $key);
    }

    /**
     * Extract the one optimistic version named by an already-parsed `If-Match` condition.
     *
     * Business-record mutations require one canonical Kumwe tag rather than a wildcard or tag list because
     * the integer is part of the application command and its idempotency fingerprint. Pre-reading the row
     * to choose a version would break replay: after the first successful write, an identical retry would see
     * the newer row and fail before `BusinessRecordService` could return its stored outcome. The middleware
     * attribute remains the parser authority; the raw line is used only to extract the version and the parsed
     * object must strongly match the reconstructed tag before that version is accepted.
     *
     * @param   ServerRequestInterface  $request  Mutation request after precondition middleware.
     *
     * @return  int  Positive expected version carried into the compare-and-set command.
     *
     * @throws  BusinessRecordPreconditionFailed  When absent, wildcard, multi-tag or not a canonical `vN` tag.
     *
     * @since   2.0.0
     */
    public static function expectedVersion(ServerRequestInterface $request): int
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);
        $header = trim($request->getHeaderLine('If-Match'));
        if (
            !$condition instanceof IfMatch
            || $condition->isWildcard()
            || preg_match('/^"v([1-9][0-9]*)"$/D', $header, $matches) !== 1
        ) {
            throw new BusinessRecordPreconditionFailed();
        }
        $digits = $matches[1];
        if (
            strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
        ) {
            throw new BusinessRecordPreconditionFailed();
        }
        $version = (int) $digits;
        if (!$condition->matches(EntityTag::fromVersion($version))) {
            throw new BusinessRecordPreconditionFailed();
        }

        return $version;
    }

    /**
     * Convert one nested decoded body value while enforcing depth and node budgets.
     *
     * @param   mixed  $value  Decoded scalar, object or list.
     * @param   int    $depth  Current structural depth.
     * @param   int    $nodes  Shared node counter for the whole document.
     *
     * @return  mixed  Scalar unchanged, object as map or list as list.
     *
     * @throws  InvalidArgumentException  When depth or node count exceeds its cap.
     *
     * @since   2.0.0
     */
    private static function normalizeValue(mixed $value, int $depth, int &$nodes): mixed
    {
        ++$nodes;
        if ($depth > 12 || $nodes > self::MAX_DOCUMENT_NODES) {
            throw new InvalidArgumentException('The business-record request document exceeds its safe bounds.');
        }
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $member) {
                $result[$key] = self::normalizeValue($member, $depth + 1, $nodes);
            }

            return $result;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $member) {
                $result[$key] = self::normalizeValue($member, $depth + 1, $nodes);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Normalize one parsed query map recursively.
     *
     * @param   array<mixed>  $document  Query map or nested list.
     * @param   int           $depth     Current query nesting.
     * @param   int           $nodes     Shared node counter.
     *
     * @return  array<mixed>  Recursively normalized query members.
     *
     * @throws  InvalidArgumentException  When a typed scalar, depth or node count is invalid.
     *
     * @since   2.0.0
     */
    private static function normalizeQueryMap(array $document, int $depth, int &$nodes): array
    {
        if ($depth > 12) {
            throw new InvalidArgumentException('The business-record query exceeds its safe nesting bound.');
        }
        $result = [];
        foreach ($document as $key => $value) {
            ++$nodes;
            if ($nodes > self::MAX_DOCUMENT_NODES) {
                throw new InvalidArgumentException('The business-record query exceeds its safe node bound.');
            }
            if (is_array($value)) {
                $result[$key] = self::normalizeQueryMap($value, $depth + 1, $nodes);
                continue;
            }
            if (in_array($key, ['page_size', 'limit', 'before_version'], true)) {
                if (is_int($value)) {
                    $result[$key] = $value;
                    continue;
                }
                if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
                    throw new InvalidArgumentException('A business-record query integer is invalid.');
                }
                $digits = ltrim($value, '0');
                $digits = $digits === '' ? '0' : $digits;
                if (
                    strlen($digits) > strlen((string) PHP_INT_MAX)
                    || (strlen($digits) === strlen((string) PHP_INT_MAX)
                        && strcmp($digits, (string) PHP_INT_MAX) > 0)
                ) {
                    throw new InvalidArgumentException('A business-record query integer is outside its safe bound.');
                }
                $result[$key] = (int) $digits;
                continue;
            }
            if (in_array($key, ['include_archived', 'include_deleted', 'nulls_last', 'negated', 'is_null'], true)) {
                if (is_bool($value)) {
                    $result[$key] = $value;
                    continue;
                }
                $result[$key] = match ($value) {
                    '1', 'true' => true,
                    '0', 'false' => false,
                    default => throw new InvalidArgumentException('A business-record query boolean is invalid.'),
                };
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('A business-record query scalar must be a string.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Reject a recursive filter before factory construction if it exceeds 64 nodes or safe depth/hops.
     *
     * @param   mixed  $filter  Optional decoded filter root.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the early structural budget is exceeded.
     *
     * @since   2.0.0
     */
    private static function assertFilterBudget(mixed $filter): void
    {
        if (!is_array($filter)) {
            return;
        }
        $nodes = 0;
        self::walkFilter($filter, 1, 0, $nodes);
    }

    /**
     * Walk the recognizable recursive edges of one filter document for an early resource budget.
     *
     * Malformed shapes are left for the closed query factory to describe. This pass only stops a wide or
     * deep input before constructors recurse through all of it.
     *
     * @param   array<mixed>  $filter         Candidate filter node.
     * @param   int           $depth          Current filter depth.
     * @param   int           $relationDepth  Relationship hops traversed so far.
     * @param   int           $nodes          Shared filter-node counter.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the 64-node, eight-level or two-hop bound is exceeded.
     *
     * @since   2.0.0
     */
    private static function walkFilter(array $filter, int $depth, int $relationDepth, int &$nodes): void
    {
        ++$nodes;
        if ($nodes > 64 || $depth > 8 || $relationDepth > 2) {
            throw new InvalidArgumentException('The business-record filter exceeds its safe bounds.');
        }
        if (($filter['type'] ?? null) === 'boolean' && is_array($filter['children'] ?? null)) {
            foreach ($filter['children'] as $child) {
                if (is_array($child)) {
                    self::walkFilter($child, $depth + 1, $relationDepth, $nodes);
                }
            }
        }
        if (($filter['type'] ?? null) === 'relation' && is_array($filter['target'] ?? null)) {
            self::walkFilter($filter['target'], $depth + 1, $relationDepth + 1, $nodes);
        }
    }

    /**
     * Prevent instantiation; request parsing has no mutable state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
