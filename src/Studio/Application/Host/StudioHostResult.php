<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use JsonException;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * Closed successful value returned by one Studio host port implementation.
 *
 * @since  2.0.0
 */
final readonly class StudioHostResult
{
    /**
     * Retain a JSON value and its optional authoritative artifact revision.
     *
     * @param  mixed        $value     Canonical JSON value.
     * @param  string|null  $revision  Authoritative revision after an artifact operation.
     *
     * @since  2.0.0
     */
    public function __construct(public mixed $value, public ?string $revision = null)
    {
        CanonicalJson::stringify($this->document());
    }

    /**
     * Project this value into the closed host-result wire document.
     *
     * @return  stdClass  Document carrying only `value` and optional `revision`.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        $document = new stdClass();
        $document->value = $this->value;
        if ($this->revision !== null) {
            $document->revision = $this->revision;
        }

        return $document;
    }

    /**
     * Recover and re-prove a completed idempotency result from canonical bytes.
     *
     * @param   string  $bytes  Canonical persisted host-result bytes.
     *
     * @return  self  Reconstituted successful result.
     *
     * @throws  RuntimeException  When storage is malformed or no longer canonical.
     *
     * @since   2.0.0
     */
    public static function fromCanonicalBytes(string $bytes): self
    {
        try {
            $document = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('A stored Studio host result is corrupt.', 0, $exception);
        }
        if (!$document instanceof stdClass || !property_exists($document, 'value')) {
            throw new RuntimeException('A stored Studio host result is corrupt.');
        }
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);
        if ($members !== ['value'] && $members !== ['revision', 'value']) {
            throw new RuntimeException('A stored Studio host result is corrupt.');
        }
        $revision = property_exists($document, 'revision') ? $document->revision : null;
        if ($revision !== null && (!is_string($revision) || $revision === '')) {
            throw new RuntimeException('A stored Studio host result is corrupt.');
        }
        if (!hash_equals($bytes, CanonicalJson::stringify($document))) {
            throw new RuntimeException('A stored Studio host result is not canonical.');
        }

        return new self($document->value, $revision);
    }
}
