<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

/**
 * Last-line redaction applied to audit metadata on its way out of the trail into an archive.
 *
 * Services already record safe context only, so this is a belt-and-braces control rather than the
 * primary one: an archive leaves the installation, often to a ticket or an auditor, and a single
 * credential-shaped value that slipped past a service's discipline would leave with it. Keys are matched
 * against the credential vocabulary `docs/business-security.md` forbids in logs — password, secret,
 * token, key, code, signature, cookie, authorization — and long opaque values are replaced whatever
 * their key is called. Redaction runs on the export path only; the stored row and therefore the row's
 * digest are never touched, so a redacted archive and the live trail still verify against each other
 * through the anchors rather than through the archive's bytes.
 *
 * @since  2.0.0
 */
final class AuditMetadataRedactor
{
    /**
     * Placeholder written in place of a value that must not leave the installation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PLACEHOLDER = '[redacted]';

    /**
     * Key fragments whose presence marks the value as credential-shaped.
     *
     * Keys are compared with every separator removed, so `api-key`, `api_key` and `API Key` all reduce
     * to the same fragment and no spelling of a credential slips through.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array KEY_FRAGMENTS = [
        'apikey',
        'authorization',
        'cookie',
        'credential',
        'passphrase',
        'password',
        'private',
        'recoverycode',
        'secret',
        'signature',
        'token',
    ];

    /**
     * Byte length above which an opaque single-token string is redacted whatever its key is.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int OPAQUE_LENGTH = 128;

    /**
     * Redact credential-shaped entries anywhere in one metadata document.
     *
     * @param   array<string, mixed>  $metadata  Decoded metadata object as the row stores it.
     * @param   int                   $redacted  Running count of replaced values, raised in place.
     *
     * @return  array<string, mixed>  The document with every matched value replaced by the placeholder.
     *
     * @since   2.0.0
     */
    public static function redact(array $metadata, int &$redacted): array
    {
        foreach ($metadata as $key => $value) {
            if (self::matches($key, $value)) {
                $metadata[$key] = self::PLACEHOLDER;
                $redacted++;
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $metadata[$key] = self::redact($value, $redacted);
            }
        }

        return $metadata;
    }

    /**
     * Decide whether one metadata entry must be replaced.
     *
     * @param   int|string  $key    Metadata key as stored; a list position carries no naming signal.
     * @param   mixed       $value  Metadata value as stored.
     *
     * @return  bool  True when the key names a credential or the value is a long opaque token.
     *
     * @since   2.0.0
     */
    private static function matches(int|string $key, mixed $value): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $key)) ?? '';
        foreach (self::KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return is_string($value)
            && strlen($value) > self::OPAQUE_LENGTH
            && preg_match('/^[A-Za-z0-9+\/_=.:-]+$/D', $value) === 1;
    }
}
