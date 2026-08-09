<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;

/**
 * Response an idempotent operation produced, kept so a repeat of the request can be answered from it.
 *
 * An `IdempotencyRecord` carries one of these exactly while it is `COMPLETED`. What is kept is
 * deliberately narrow — a status code and a decoded body, no headers — because that is the whole of
 * what a replay reproduces. Alongside them sits a digest of the body, taken at construction from its
 * canonical encoding, so a store that writes the body out and reads it back later can prove the payload
 * it recovered is still the response that was captured rather than replay something altered in transit.
 *
 * @since  2.0.0
 */
final readonly class IdempotencyResult
{
    /**
     * Decoded response payload handed back on replay.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $body;
    /**
     * Fingerprint of the body, taken once at construction and never recomputed.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $bodyDigest;

    /**
     * Capture the status and body a finished operation answered with.
     *
     * @param   int                   $statusCode  HTTP status the operation answered with.
     * @param   array<string, mixed>  $body        Response payload to replay, decoded rather than serialised.
     *
     * @throws  InvalidArgumentException  When the status falls outside the 100 to 599 range, or the body holds
     *          a value canonical JSON cannot represent.
     *
     * @since   2.0.0
     */
    public function __construct(private int $statusCode, array $body)
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('An idempotent result requires a valid HTTP status code.');
        }

        $this->body = $body;
        $this->bodyDigest = CanonicalJson::digest($body);
    }

    /**
     * Return the status a replay should answer with.
     *
     * @return  int  HTTP status code, between 100 and 599 inclusive.
     *
     * @since   2.0.0
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Return the payload a replay should answer with.
     *
     * @return  array<string, mixed>  The payload exactly as captured; `bodyDigest()` fingerprints this
     *          same value, so a store can re-derive the digest and check what it persisted.
     *
     * @since   2.0.0
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * Return the fingerprint a store compares a persisted body against.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the body's canonical encoding, 64 characters wide.
     *
     * @since   2.0.0
     */
    public function bodyDigest(): string
    {
        return $this->bodyDigest;
    }
}
