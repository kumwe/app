<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use RuntimeException;

/**
 * A runtime publication document paired with the check that entitles anyone to act on what it names.
 *
 * `ExtensionRuntimeMapCompiler` wraps each document it verifies in this, and a
 * `RuntimeMaterializationState` carries it as the map the replica is serving. Construction deliberately
 * proves nothing: the guarantee is discharged by `assertIntegrity()`, which `ExtensionRuntimeLoader`
 * calls again before it will execute a single provider the document names. Re-proving at the point of
 * use rather than trusting whoever last read the file is the point of the type, so a document that has
 * travelled through a container, a cache or a serialized state still has to answer for itself.
 *
 * @since  2.0.0
 */
final readonly class VerifiedRuntimePublication
{
    /**
     * Wrap a decoded publication document without yet asserting anything about it.
     *
     * @param  array<string, mixed>  $document  Decoded `kumwe-extension-map-v3` document, still to be
     *         held against the key ring before anything in it is believed.
     *
     * @since  2.0.0
     */
    public function __construct(public array $document)
    {
    }

    /**
     * Prove the document is the one that was signed and that it describes the state it claims to.
     *
     * Three things are established in turn: the envelope is a well-formed `kumwe-extension-map-v3` with
     * a positive generation and a list of extensions; the publication and state checksums recomputed
     * over its canonical encoding match the ones it carries; and the trust HMAC over
     * `generation:publicationChecksum` verifies under the key the document names. Deployed bytes are
     * deliberately not re-digested here — that cost belongs to materialization — which is what makes
     * this cheap enough to repeat on every load.
     *
     * @param   RuntimePublicationKeyRing  $keys  Ring the document's own signing key identifier is
     *          resolved against, including keys the installation has rotated away from.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the envelope is malformed, a required field is missing or empty,
     *          either recomputed checksum disagrees with the document, or the signature is invalid or
     *          names a key the ring does not hold.
     *
     * @since   2.0.0
     */
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
