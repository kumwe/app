<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonException;

/**
 * A signed statement by an upstream issuer that named signing keys must no longer be trusted.
 *
 * The wire form is an envelope carrying the statement as an opaque string plus a detached Ed25519
 * signature over exactly those bytes. That is deliberate and it is the whole reason the format is not
 * a plain signed JSON object: signing the serialized text removes canonicalization from the trust
 * decision, so an issuer and an installation never have to agree on key order, whitespace or number
 * formatting for a signature to hold.
 *
 * Three properties make a list safe to act on automatically. It is signed by a key pinned in the
 * installation's own configuration rather than by anything in the trust store the list revokes. It
 * carries a strictly increasing `sequence`, so an attacker who can serve stale bytes cannot roll an
 * installation back to a list that omits a revocation. And it carries `valid_until`, so an issuer can
 * bound how long a list may be believed even if it is replayed inside its sequence.
 *
 * @since  2.0.0
 */
final readonly class RevocationList
{
    /**
     * Stable envelope format identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ENVELOPE_FORMAT = 'kumwe-extension-revocation-envelope-v1';

    /**
     * Stable statement format identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string FORMAT = 'kumwe-extension-revocation-v1';

    /**
     * Largest fetched document accepted, bounding both parsing and transport.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_BYTES = 1_048_576;

    /**
     * Freeze one validated statement together with the bytes and signature it arrived as.
     *
     * @param  string                                       $issuer           Issuer name the statement declares.
     * @param  int                                          $sequence         Monotonic list number; a fetch is
     *         applied only when this exceeds the sequence already recorded for the origin.
     * @param  DateTimeImmutable                            $issuedAt         When the issuer produced the list.
     * @param  DateTimeImmutable                            $validUntil       After which the list must not be
     *         believed even if it verifies.
     * @param  list<array{key_id: string, reason: string}>  $revokedKeys      Keys the issuer withdraws, sorted
     *         by identifier and free of duplicates.
     * @param  string                                       $signedBytes      Exact statement text the signature
     *         covers.
     * @param  string                                       $signatureBase64  Detached Ed25519 signature in
     *         standard base64.
     * @param  string                                       $documentSha256   SHA-256 of the whole envelope, so a
     *         re-fetch of the same bytes is recognisable.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $issuer,
        public int $sequence,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $validUntil,
        public array $revokedKeys,
        public string $signedBytes,
        public string $signatureBase64,
        public string $documentSha256,
    ) {
    }

    /**
     * Decode a fetched envelope and refuse anything an installation must not act on.
     *
     * Parsing is strict in both directions — an unknown key is a refusal, not something to ignore —
     * because this is the one document in the system whose effect is to disable other people's
     * extensions. Nothing about the signature is checked here; `verify()` is the separate step, so a
     * caller cannot mistake a well-formed document for a trusted one.
     *
     * @param   string  $payload  Raw envelope bytes as fetched from the configured origin.
     *
     * @return  self  Structurally valid, still-unverified statement.
     *
     * @throws  InvalidArgumentException  When the envelope is oversized, is not an object of exactly the
     *          declared keys, declares an unsupported format or algorithm, or carries a malformed
     *          statement, instant, sequence or revocation entry.
     * @throws  JsonException  When either the envelope or the statement is malformed JSON.
     *
     * @since   2.0.0
     */
    public static function fromEnvelope(string $payload): self
    {
        if ($payload === '' || strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('A revocation feed document must be between 1 byte and 1 MiB.');
        }
        $envelope = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new InvalidArgumentException('A revocation feed document must be a JSON object.');
        }
        $keys = array_keys($envelope);
        sort($keys, SORT_STRING);
        if ($keys !== ['algorithm', 'document', 'format', 'key_id', 'signature']) {
            throw new InvalidArgumentException('The revocation feed envelope contains an unknown or missing key.');
        }
        if (
            ($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || ($envelope['algorithm'] ?? null) !== 'ed25519'
        ) {
            throw new InvalidArgumentException('The revocation feed envelope format is unsupported.');
        }
        $signed = $envelope['document'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if (!is_string($signed) || !is_string($signature) || !is_string($envelope['key_id'] ?? null)) {
            throw new InvalidArgumentException('A revocation feed envelope field has an invalid type.');
        }
        $decodedSignature = base64_decode($signature, true);
        if (!is_string($decodedSignature) || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException('The revocation feed signature is not a 64-byte Ed25519 signature.');
        }
        $statement = self::statement($signed);

        return new self(
            $statement['issuer'],
            $statement['sequence'],
            $statement['issued_at'],
            $statement['valid_until'],
            $statement['revoked_keys'],
            $signed,
            $signature,
            hash('sha256', $payload),
        );
    }

    /**
     * Report whether the list is still inside the freshness window its issuer declared.
     *
     * @param   DateTimeImmutable  $at  Instant to judge the window against, normally the clock's now.
     *
     * @return  bool  True while `valid_until` has not passed.
     *
     * @since   2.0.0
     */
    public function isCurrentAt(DateTimeImmutable $at): bool
    {
        return $this->validUntil >= $at;
    }

    /**
     * List the key identifiers this statement withdraws.
     *
     * @return  list<string>  Identifiers in sorted order.
     *
     * @since   2.0.0
     */
    public function revokedKeyIds(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['key_id'],
            $this->revokedKeys,
        );
    }

    /**
     * Find the issuer's stated reason for withdrawing one key.
     *
     * @param   string  $keyId  Key identifier to look up.
     *
     * @return  ?string  The reason, or null when the statement does not withdraw that key.
     *
     * @since   2.0.0
     */
    public function reasonFor(string $keyId): ?string
    {
        foreach ($this->revokedKeys as $entry) {
            if ($entry['key_id'] === $keyId) {
                return $entry['reason'];
            }
        }

        return null;
    }

    /**
     * Decode and validate the signed statement carried inside the envelope.
     *
     * @param   string  $signed  Exact statement text the signature covers.
     *
     * @return  array{issuer: string, sequence: int, issued_at: DateTimeImmutable,
     *          valid_until: DateTimeImmutable, revoked_keys: list<array{key_id: string, reason: string}>}
     *          The validated statement fields.
     *
     * @throws  InvalidArgumentException  When the statement's shape, format, instants, sequence or
     *          revocation entries are invalid.
     * @throws  JsonException  When the statement is malformed JSON.
     *
     * @since   2.0.0
     */
    private static function statement(string $signed): array
    {
        $document = json_decode($signed, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('The revocation statement must be a JSON object.');
        }
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'issued_at', 'issuer', 'revoked_keys', 'sequence', 'valid_until']) {
            throw new InvalidArgumentException('The revocation statement contains an unknown or missing key.');
        }
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('The revocation statement format is unsupported.');
        }
        $issuer = $document['issuer'] ?? null;
        if (!is_string($issuer) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,126}$/D', $issuer) !== 1) {
            throw new InvalidArgumentException('The revocation statement issuer is invalid.');
        }
        $sequence = $document['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 1) {
            throw new InvalidArgumentException('The revocation statement sequence must be a positive integer.');
        }

        return [
            'issuer' => $issuer,
            'sequence' => $sequence,
            'issued_at' => self::instant($document['issued_at'] ?? null, 'issued_at'),
            'valid_until' => self::instant($document['valid_until'] ?? null, 'valid_until'),
            'revoked_keys' => self::revocations($document['revoked_keys'] ?? null),
        ];
    }

    /**
     * Parse one declared instant in strict RFC 3339 form.
     *
     * @param   mixed   $value  Field value as decoded.
     * @param   string  $field  Field name quoted in the failure message.
     *
     * @return  DateTimeImmutable  The parsed instant.
     *
     * @throws  InvalidArgumentException  When the value is not a parseable RFC 3339 timestamp.
     *
     * @since   2.0.0
     */
    private static function instant(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The revocation statement %s must be a string.', $field));
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $failure) {
            throw new InvalidArgumentException(
                sprintf('The revocation statement %s is not a valid instant.', $field),
                0,
                $failure,
            );
        }
    }

    /**
     * Validate the withdrawal entries and return them sorted and de-duplicated.
     *
     * @param   mixed  $value  Field value as decoded.
     *
     * @return  list<array{key_id: string, reason: string}>  Entries sorted by key identifier.
     *
     * @throws  InvalidArgumentException  When the list, an entry, an identifier or a reason is invalid,
     *          when an identifier repeats, or when more than 1000 entries are declared.
     *
     * @since   2.0.0
     */
    private static function revocations(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('The revocation statement must carry a list of revoked keys.');
        }
        if (count($value) > 1_000) {
            throw new InvalidArgumentException('A revocation statement cannot withdraw more than 1000 keys.');
        }
        $entries = [];
        $seen = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('Every revoked-key entry must be a JSON object.');
            }
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if ($keys !== ['key_id', 'reason']) {
                throw new InvalidArgumentException('A revoked-key entry contains an unknown or missing key.');
            }
            $keyId = $entry['key_id'] ?? null;
            $reason = $entry['reason'] ?? null;
            if (!is_string($keyId) || preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
                throw new InvalidArgumentException('A revoked-key entry names an invalid key identifier.');
            }
            if (!is_string($reason) || $reason === '' || strlen($reason) > 500) {
                throw new InvalidArgumentException('A revoked-key entry must carry a reason of 1 to 500 bytes.');
            }
            if (isset($seen[$keyId])) {
                throw new InvalidArgumentException('A revocation statement names the same key twice.');
            }
            $seen[$keyId] = true;
            $entries[] = ['key_id' => $keyId, 'reason' => $reason];
        }
        usort($entries, static fn (array $left, array $right): int => $left['key_id'] <=> $right['key_id']);

        return $entries;
    }
}
