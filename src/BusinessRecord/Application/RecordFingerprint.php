<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;

/**
 * Keyed digest over any record-shaped value, for the comparisons that outlive the request that made them.
 *
 * Business-record writes are idempotent, so a repeated command has to be recognised as the same command
 * and its stored outcome replayed instead of applied again. That comparison spans processes and hours
 * and therefore runs over digests rather than the values themselves: the idempotency scope, the request
 * body, the stored result, and — in the history path — a record identity whose row may no longer exist,
 * are all reduced through here. Canonicalization happens before hashing, so two requests that differ
 * only in key order, or in how a decimal or a timestamp was spelled, fingerprint identically and replay
 * correctly. The digest is keyed rather than plain, so a value cannot be fingerprinted from outside the
 * installation and a stored digest reveals nothing about the request it stands for.
 *
 * @since  2.0.0
 */
final readonly class RecordFingerprint
{
    /**
     * Bind the fingerprinter to the key every digest it produces is taken under.
     *
     * @param   string  $key  Raw HMAC key, at least 32 bytes; `ContainerFactory` derives it from the
     *          application secret under a fingerprint-specific label.
     *
     * @throws  InvalidArgumentException  When the key is shorter than 32 bytes.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The business-record fingerprint key requires at least 32 bytes.');
        }
    }

    /**
     * Reduce a value to the keyed digest that stands in for it wherever it is compared later.
     *
     * Equal values always digest equally, whatever order their keys arrived in; unequal values are only
     * as likely to collide as SHA-256 allows.
     *
     * @param   mixed  $value  Anything the record layer handles — scalars, nested arrays, and the domain
     *          value objects `RecordValueGuard` knows how to reduce.
     *
     * @return  string  Lowercase hexadecimal HMAC-SHA256, 64 characters wide.
     *
     * @throws  InvalidArgumentException  When the value holds a float, a resource or an object outside the
     *          supported set, or cannot be encoded as JSON — nesting past the encoder's depth limit and a
     *          malformed UTF-8 string being the cases that reach it that way.
     *
     * @since   2.0.0
     */
    public function digest(mixed $value): string
    {
        try {
            $json = json_encode(
                $this->canonical($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A business-record request cannot be fingerprinted.', 0, $exception);
        }

        return hash_hmac('sha256', $json, $this->key);
    }

    /**
     * Reduce a value to the byte-stable shape the digest is actually taken over.
     *
     * `RecordValueGuard::canonical()` does the substitution — decimals, money, quantities, timestamps and
     * encrypted envelopes become their storage spellings, and anything it cannot represent is refused.
     * The walk here re-applies that to every element and sorts string-keyed arrays with `SORT_STRING`, so
     * key order never reaches the hash while list order, which carries meaning, is left as it stands.
     *
     * @param   mixed  $value  Value to reduce.
     *
     * @return  mixed  Null, bool, int, string, or arrays of those; no object survives the reduction.
     *
     * @throws  InvalidArgumentException  When a leaf of the value is a float, a resource, or an object
     *          `RecordValueGuard` has no storage spelling for.
     *
     * @since   2.0.0
     */
    private function canonical(mixed $value): mixed
    {
        $value = RecordValueGuard::canonical($value);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonical($item);
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        }

        return $value;
    }
}
