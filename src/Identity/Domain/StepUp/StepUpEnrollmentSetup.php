<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One-time enrollment material shown before a TOTP credential is confirmed.
 *
 * @since  2.0.0
 */
final readonly class StepUpEnrollmentSetup
{
    /**
     * Carry the secret exactly once from provider to the enrollment screen.
     *
     * @param  string             $enrollmentId  Pending credential UUID.
     * @param  string             $secret        RFC 4648 Base32 authenticator secret.
     * @param  string             $provisioningUri `otpauth://` URI for a QR encoder.
     * @param  DateTimeImmutable  $expiresAt     Exclusive confirmation deadline.
     *
     * @throws InvalidArgumentException  When the identifier, secret, or provisioning URI is invalid.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $enrollmentId,
        public string $secret,
        public string $provisioningUri,
        public DateTimeImmutable $expiresAt,
    ) {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($uuid, $enrollmentId) !== 1) {
            throw new InvalidArgumentException('A step-up enrollment ID must be a canonical UUID.');
        }
        if (preg_match('/^[A-Z2-7]{16,128}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('A step-up enrollment secret is invalid.');
        }
        $parts = parse_url($provisioningUri);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'otpauth'
            || ($parts['host'] ?? null) !== 'totp'
            || !is_string($parts['path'] ?? null)
            || $parts['path'] === ''
            || !is_string($parts['query'] ?? null)
            || strlen($provisioningUri) > 4096
        ) {
            throw new InvalidArgumentException('A step-up provisioning URI is invalid.');
        }
        parse_str($parts['query'], $query);
        if (($query['secret'] ?? null) !== $secret || !is_string($query['issuer'] ?? null)) {
            throw new InvalidArgumentException('A step-up provisioning URI does not match its secret.');
        }
    }
}
