<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

/**
 * Portable detached-signature sidecar for a deterministic extension package.
 *
 * @since  2.0.0
 */
final readonly class SignatureDocument
{
    /**
     * Stable sidecar format identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const FORMAT = 'kumwe-extension-signature-v1';

    /**
     * Trust-store identifier of the public key matching this signature.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $keyId;

    /**
     * Canonical lowercase SHA-256 digest of the exact package bytes.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $packageSha256;

    /**
     * Canonical base64 encoding of the detached Ed25519 signature.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $base64Signature;

    /**
     * Validate the digest, key identifier, and detached Ed25519 signature.
     *
     * @param   string  $keyId            Trust-store key identifier.
     * @param   string  $packageSha256    Lowercase hexadecimal package checksum.
     * @param   string  $base64Signature  Detached Ed25519 signature in strict base64 form.
     *
     * @throws  InvalidArgumentException  When any signature field is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $keyId,
        string $packageSha256,
        string $base64Signature,
    ) {
        $checksum = PackageChecksum::sha256($packageSha256);
        $signature = PackageSignature::ed25519($keyId, $base64Signature);
        $this->keyId = $signature->keyId();
        $this->packageSha256 = (string) $checksum;
        $this->base64Signature = $signature->asBase64();
    }

    /**
     * Decode a strict signature sidecar and reject every unknown or missing semantic field.
     *
     * @param   string  $json  Raw sidecar document.
     *
     * @return  self  Validated signature document.
     *
     * @throws  InvalidArgumentException  When the shape, format, or a field is invalid.
     * @throws  JsonException  When the JSON is malformed or too deeply nested.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > 4_096) {
            throw new InvalidArgumentException('An extension signature document cannot exceed 4096 bytes.');
        }
        $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('An extension signature document must be a JSON object.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['algorithm', 'format', 'key_id', 'package_sha256', 'signature']) {
            throw new InvalidArgumentException('An extension signature document contains an unknown or missing key.');
        }
        if (($value['format'] ?? null) !== self::FORMAT || ($value['algorithm'] ?? null) !== 'ed25519') {
            throw new InvalidArgumentException('The extension signature document format is unsupported.');
        }
        $keyId = $value['key_id'] ?? null;
        $packageSha256 = $value['package_sha256'] ?? null;
        $signature = $value['signature'] ?? null;
        if (!is_string($keyId) || !is_string($packageSha256) || !is_string($signature)) {
            throw new InvalidArgumentException('An extension signature document field has an invalid type.');
        }

        return new self($keyId, $packageSha256, $signature);
    }

    /**
     * Export the stable sidecar object.
     *
     * @return  array{format: string, algorithm: string, key_id: string, package_sha256: string, signature: string}
     *          Detached signature fields in canonical order.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'algorithm' => 'ed25519',
            'key_id' => $this->keyId,
            'package_sha256' => $this->packageSha256,
            'signature' => $this->base64Signature,
        ];
    }

    /**
     * Encode the sidecar deterministically for storage and transport.
     *
     * @return  string  Pretty-printed JSON ending with one newline.
     *
     * @throws  JsonException  When an internal field cannot be encoded.
     *
     * @since   2.0.0
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
