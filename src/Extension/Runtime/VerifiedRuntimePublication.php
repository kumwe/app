<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use RuntimeException;

final readonly class VerifiedRuntimePublication
{
    /** @param array<string, mixed> $document */
    public function __construct(public array $document)
    {
    }

    public function assertIntegrity(RuntimePublicationKeyRing $keys): void
    {
        $generation = $this->document['generation'] ?? null;
        $extensions = $this->document['extensions'] ?? null;
        if (
            ($this->document['format'] ?? null) !== 'kumwe-extension-map-v3'
            || !is_int($generation)
            || $generation < 1
            || !is_array($extensions)
            || !array_is_list($extensions)
        ) {
            throw new RuntimeException('The verified runtime publication structure is invalid.');
        }
        $required = static function (array $source, string $field): string {
            $value = $source[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new RuntimeException(sprintf('Runtime publication field %s is invalid.', $field));
            }

            return $value;
        };
        $base = [
            'format' => 'kumwe-extension-map-v3',
            'generation' => $generation,
            'state_sha256' => $required($this->document, 'state_sha256'),
            'action' => $required($this->document, 'action'),
            'signing_key_id' => $required($this->document, 'signing_key_id'),
            'extensions' => $extensions,
        ];
        $checksum = hash('sha256', RuntimeCanonicalJson::encode($base));
        if (
            !hash_equals($checksum, $required($this->document, 'publication_sha256'))
            || !hash_equals(
                hash('sha256', RuntimeCanonicalJson::encode($extensions)),
                $required($this->document, 'state_sha256'),
            )
        ) {
            throw new RuntimeException('The verified runtime publication digest is invalid.');
        }
        $keys->assertSignature(
            $required($this->document, 'signing_key_id'),
            $generation . ':' . $checksum,
            $required($this->document, 'trust_hmac'),
        );
    }
}
