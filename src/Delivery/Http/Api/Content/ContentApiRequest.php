<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * Reads and validates the parts of an HTTP request that the content API handlers all depend on.
 *
 * Every content route shares one request grammar — a JSON object body, an `id` route segment, an
 * authenticated principal, an `If-Match` precondition — so parsing it in one place keeps each handler
 * to the operation it actually performs. A validation failure leaves as `InvalidArgumentException`
 * and a stale precondition as `PreconditionFailed`, which `ContentApiResponder` already maps to 422
 * and 412; that is why a handler can call these accessors straight from its try block without
 * inspecting the values first. Nothing here is stored: each call reads the request it is given.
 *
 * @since  2.0.0
 */
final class ContentApiRequest
{
    /**
     * Decode the request body as a JSON object and return its top-level members.
     *
     * Decoding is depth limited and produces objects rather than associative arrays, which is what
     * lets `data()` tell a nested JSON object apart from a JSON array further down the document.
     *
     * @param   ServerRequestInterface  $request  Request whose body carries the JSON document.
     *
     * @return  array<string, mixed>  Top-level members; nested objects are still `stdClass`.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON, or is not a JSON object.
     *
     * @since   2.0.0
     */
    public static function json(ServerRequestInterface $request): array
    {
        try {
            $data = json_decode((string) $request->getBody(), false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }

        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $body */
        $body = get_object_vars($data);

        return $body;
    }

    /**
     * Return the principal the API authentication middleware attached to this request.
     *
     * @param   ServerRequestInterface  $request  Request that has already passed API authentication.
     *
     * @return  AuthenticatedPrincipal  The authenticated caller behind the request.
     *
     * @throws  InvalidArgumentException  When the request carries no authenticated principal.
     *
     * @since   2.0.0
     */
    public static function principal(ServerRequestInterface $request): AuthenticatedPrincipal
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new InvalidArgumentException('An authenticated principal is required.');
        }

        return $principal;
    }

    /**
     * Return the content identifier the router captured from the request path.
     *
     * @param   ServerRequestInterface  $request  Request matched by a route carrying an `id` segment.
     *
     * @return  string  The `id` route attribute; non-empty, but otherwise unvalidated here.
     *
     * @throws  InvalidArgumentException  When the route attribute is absent, empty, or not a string.
     *
     * @since   2.0.0
     */
    public static function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The content route identifier is missing.');
        }

        return $id;
    }

    /**
     * Read a mandatory string field from a decoded body, trimmed of surrounding whitespace.
     *
     * @param   array<string, mixed>  $body   Decoded request body the field is read from.
     * @param   string                $field  Member name, quoted verbatim in the failure message.
     *
     * @return  string  The trimmed value, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the member is absent, is not a string, or is blank once
     *          trimmed.
     *
     * @since   2.0.0
     */
    public static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /**
     * Read a string field that callers may omit, falling back to a supplied default.
     *
     * Omission and emptiness are answered differently: an absent member yields the default, while a
     * member that is present but blank or not a string is still a validation failure. That is what
     * lets a create request leave `content_type` unset without letting it be sent as `""`.
     *
     * @param   array<string, mixed>  $body     Decoded request body the field is read from.
     * @param   string                $field    Member name, quoted verbatim in the failure message.
     * @param   string                $default  Value returned when the member is absent entirely.
     *
     * @return  string  The trimmed value, or the default when the member was not sent.
     *
     * @throws  InvalidArgumentException  When the member is present but is not a non-empty string.
     *
     * @since   2.0.0
     */
    public static function optionalString(array $body, string $field, string $default): string
    {
        return array_key_exists($field, $body) ? self::requiredString($body, $field) : $default;
    }

    /**
     * Read the entry's `data` payload as a plain nested array.
     *
     * Decoded objects are converted to arrays at every depth, including inside lists, so the content
     * domain and the schema validator only ever meet arrays. An absent `data` member yields an empty
     * array, which is how an entry is created carrying no field values at all.
     *
     * @param   array<string, mixed>  $body  Decoded request body holding the optional `data` member.
     *
     * @return  array<string, mixed>  The payload, with every nested object turned into an array.
     *
     * @throws  InvalidArgumentException  When `data` is present but is not a JSON object.
     *
     * @since   2.0.0
     */
    public static function data(array $body): array
    {
        $data = $body['data'] ?? new stdClass();

        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException('The data field must be a JSON object.');
        }

        return self::normalizeObject($data);
    }

    /**
     * Build the publication window a request asks for, when it asks for one at all.
     *
     * The two fields are read as one unit. Sending neither means "leave the schedule alone" and
     * yields null; sending either rebuilds the whole window, so the partner field is cleared unless
     * it is resent. Null and the empty string both open that end of the window rather than failing.
     *
     * @param   array<string, mixed>  $body  Decoded request body holding the optional schedule fields.
     *
     * @return  ?PublicationWindow  The requested window, or null when the body sets no schedule.
     *
     * @throws  InvalidArgumentException  When a schedule field is neither a string nor null, or the
     *          window would end before it starts.
     * @throws  \DateMalformedStringException  When a schedule field is not a readable date and time.
     *
     * @since   2.0.0
     */
    public static function publicationWindow(array $body): ?PublicationWindow
    {
        if (!array_key_exists('publish_at', $body) && !array_key_exists('unpublish_at', $body)) {
            return null;
        }

        $publishAt = $body['publish_at'] ?? null;
        $unpublishAt = $body['unpublish_at'] ?? null;

        if ($publishAt !== null && !is_string($publishAt)) {
            throw new InvalidArgumentException('publish_at must be an RFC 3339 timestamp or null.');
        }

        if ($unpublishAt !== null && !is_string($unpublishAt)) {
            throw new InvalidArgumentException('unpublish_at must be an RFC 3339 timestamp or null.');
        }

        return new PublicationWindow(
            $publishAt === null || $publishAt === '' ? null : new DateTimeImmutable($publishAt),
            $unpublishAt === null || $unpublishAt === '' ? null : new DateTimeImmutable($unpublishAt),
        );
    }

    /**
     * Confirm the request's `If-Match` precondition still names the version about to be overwritten.
     *
     * The precondition is read from the attribute `RequireIfMatchMiddleware` publishes, so a route
     * that forgets that middleware fails closed: no attribute means no match, and the write is
     * refused rather than applied blind.
     *
     * @param   ServerRequestInterface  $request         Request carrying the parsed `If-Match` attribute.
     * @param   int                     $currentVersion  Version of the entry the handler has just read.
     *
     * @return  int  The same version, so it can be handed straight to the application call.
     *
     * @throws  PreconditionFailed  When no precondition is present, or it names a different version.
     *
     * @since   2.0.0
     */
    public static function expectedVersion(ServerRequestInterface $request, int $currentVersion): int
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);

        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($currentVersion))) {
            throw new PreconditionFailed();
        }

        return $currentVersion;
    }

    /**
     * Convert one decoded JSON object into an array, recursing through each of its members.
     *
     * @param   stdClass  $object  Decoded JSON object taken from somewhere in the request body.
     *
     * @return  array<string, mixed>  The members, with nested objects converted to arrays as well.
     *
     * @since   2.0.0
     */
    private static function normalizeObject(stdClass $object): array
    {
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($object);
        $normalized = [];
        foreach ($properties as $name => $value) {
            $normalized[$name] = self::normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Convert one decoded value, descending into objects and arrays and leaving scalars alone.
     *
     * Array keys are preserved, so a JSON array stays a list and an already-decoded map keeps its
     * keys; only the `stdClass` wrappers disappear.
     *
     * @param   mixed  $value  Any value taken from a decoded JSON document.
     *
     * @return  mixed  An array where the value was an object or array, otherwise the value unchanged.
     *
     * @since   2.0.0
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::normalizeObject($value);
        }
        if (is_array($value)) {
            return array_map(self::normalizeValue(...), $value);
        }

        return $value;
    }
}
