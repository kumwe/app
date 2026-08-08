<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessRecord\Query\CursorPosition;
use Kumwe\CMS\BusinessRecord\Query\RecordCursor;

final readonly class RecordCursorCodec
{
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The business-record cursor signing key requires at least 32 bytes.');
        }
    }

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

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

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
