<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\Extension\Spi\BusinessRecord\Query\CursorPosition;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;

/**
 * Signs and verifies the opaque page cursors a business-record browse hands back to its callers.
 *
 * A cursor leaves the process — it travels out to a client and returns on the next request — so the
 * position it carries cannot be believed on the way back. Every token is a base64url payload joined by
 * a dot to an HMAC-SHA256 over that payload, and `decode()` verifies the signature before it looks at
 * the contents, which is what stops a client from editing a sort value or a record key to page over
 * rows the original query never matched. The key is derived from the application secret in
 * `ContainerFactory`, so a cursor minted by one installation is worthless at another. Signature
 * verification only proves the token was minted here; `DoctrineBusinessRecordQueryCompiler` still
 * compares the recovered specification digest against the query being run, which is what rejects a
 * genuine cursor replayed against a different filter or sort.
 *
 * @since  2.0.0
 */
final readonly class RecordCursorCodec
{
    /**
     * Bind the codec to the key every cursor it mints and accepts is signed with.
     *
     * @param   string  $key  Raw HMAC key, at least 32 bytes; `ContainerFactory` derives it from the
     *          application secret under a cursor-specific label.
     *
     * @throws  InvalidArgumentException  When the key is shorter than 32 bytes.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The business-record cursor signing key requires at least 32 bytes.');
        }
    }

    /**
     * Sign a page position into the opaque token a caller returns to continue browsing.
     *
     * @param   CursorPosition  $position  Specification digest, sort values and record key that locate the
     *          last row of the page just returned.
     *
     * @return  RecordCursor  Token in `payload.signature` form, made only of characters that are safe in
     *          a URL or a JSON string.
     *
     * @throws  JsonException  When a sort value carried in the position cannot be encoded as JSON, a
     *          malformed UTF-8 string read from the row being the remaining case.
     *
     * @since   2.0.0
     */
    public function encode(CursorPosition $position): RecordCursor
    {
        $json = json_encode(
            $position->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $payload = self::base64UrlEncode($json);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->key, true));

        return RecordCursor::fromString($payload . '.' . $signature);
    }

    /**
     * Verify a returned token against this key and recover the page position it carries.
     *
     * The signature is compared with `hash_equals` before the payload is parsed, and what the payload
     * decodes to has to satisfy `CursorPosition` in full, so a token that survives this cannot carry a
     * digest that is not a hex checksum, a record key that is not a UUID, or more sort values than a
     * query is allowed to sort by.
     *
     * @param   RecordCursor  $cursor  Token as the caller sent it back; its `payload.signature` shape is
     *          already guaranteed by `RecordCursor`, so the split here always yields both halves.
     *
     * @return  CursorPosition  The position the token was minted from, ready to compile into a predicate.
     *
     * @throws  InvalidArgumentException  When the signature does not match this key, the payload is not
     *          valid base64url or valid JSON, is not a JSON object, lacks the specification, values and
     *          record-key entries a position is built from, or carries values `CursorPosition` refuses.
     *
     * @since   2.0.0
     */
    public function decode(RecordCursor $cursor): CursorPosition
    {
        [$payload, $signature] = explode('.', $cursor->value(), 2);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->key, true));
        if (!hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('The business-record cursor signature is invalid.');
        }
        $json = self::base64UrlDecode($payload);
        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The business-record cursor payload is invalid.', 0, $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('The business-record cursor payload must be an object.');
        }
        $specification = $document['specification'] ?? null;
        $values = $document['values'] ?? null;
        $recordKey = $document['record_key'] ?? null;
        if (!is_string($specification) || !is_array($values) || !array_is_list($values) || !is_string($recordKey)) {
            throw new InvalidArgumentException('The business-record cursor payload has an invalid shape.');
        }

        return new CursorPosition($specification, $values, $recordKey);
    }

    /**
     * Encode raw bytes in the URL-safe base64 alphabet, without padding.
     *
     * @param   string  $value  Raw bytes to encode: either the JSON payload or the binary HMAC.
     *
     * @return  string  Base64 text with `+` and `/` mapped to `-` and `_` and trailing `=` removed, which
     *          is the alphabet `RecordCursor` accepts.
     *
     * @since   2.0.0
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Restore raw bytes from the URL-safe base64 alphabet, putting the stripped padding back first.
     *
     * @param   string  $value  Unpadded URL-safe base64 text as it arrived inside a cursor.
     *
     * @return  string  The decoded bytes.
     *
     * @throws  InvalidArgumentException  When the text is not valid base64 once padding is restored.
     *
     * @since   2.0.0
     */
    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('The business-record cursor contains invalid base64 data.');
        }

        return $decoded;
    }
}
