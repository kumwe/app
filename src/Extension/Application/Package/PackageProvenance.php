<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;

/**
 * The publisher-asserted statement of how a package was produced, carried inside the package.
 *
 * The bill of materials says what is inside; this says where it came from. The fields mirror the SLSA
 * provenance predicate — a build type, a builder identity, the subject being described and the
 * materials it was assembled from — without adopting the in-toto envelope, because the envelope exists
 * to carry a signature from a build service that is a different trust domain than the publisher, and
 * here they are the same party running the same SDK. Embedding the statement in the package instead
 * puts it under the publisher's existing detached Ed25519 signature, which is the honest strength of
 * the claim: an installation can prove the publisher asserted it, not that an independent builder
 * observed it.
 *
 * Two fields are load-bearing at admission. `materials.sbom_sha256` binds this statement to the exact
 * bill of materials in the same package, so the two documents cannot be mixed between builds, and
 * `subject` must name the manifest the package actually carries, so a statement cannot be lifted from
 * one release onto another. Everything else is recorded and shown, never believed.
 *
 * No build timestamp is emitted, for the same reason the bill of materials carries none: the package
 * builder's contract is byte reproducibility, and a clock reading would break it.
 *
 * @since  2.0.0
 */
final readonly class PackageProvenance
{
    /**
     * Package path the provenance statement is carried at.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PATH = 'kumwe.provenance.json';

    /**
     * Stable statement format identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string FORMAT = 'kumwe-extension-provenance-v1';

    /**
     * Build type naming the process the subject was produced by.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BUILD_TYPE = 'https://kumwe.dev/extension/deterministic-package/v1';

    /**
     * Name of the builder that produces conforming packages.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BUILDER_NAME = 'kumwe-deterministic-package-builder';

    /**
     * Builder revision, deliberately a format revision rather than the CMS release.
     *
     * Stamping the running release here would make the same source tree build to different bytes on
     * two Kumwe versions, which is precisely the property the deterministic builder exists to hold.
     * This value changes only when the packaging rules themselves change.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BUILDER_VERSION = '1';

    /**
     * Largest provenance statement expanded during admission.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_BYTES = 16_384;

    /**
     * Build the statement for one package from its manifest and the bill of materials beside it.
     *
     * @param   ExtensionManifest  $manifest       Strict parsed manifest identifying the subject.
     * @param   string             $sbomSha256     Lowercase SHA-256 of the encoded bill of materials.
     * @param   int                $entryCount     Packaged files inventoried, excluding both attestations.
     * @param   int                $expandedBytes  Sum of those files' byte lengths.
     *
     * @return  self  Statement ready to encode into the package.
     *
     * @throws  InvalidArgumentException  When the bill-of-materials digest is not a SHA-256 value, or a
     *          count is negative.
     *
     * @since   2.0.0
     */
    public static function forPackage(
        ExtensionManifest $manifest,
        string $sbomSha256,
        int $entryCount,
        int $expandedBytes,
    ): self {
        if (preg_match('/^[a-f0-9]{64}$/D', $sbomSha256) !== 1) {
            throw new InvalidArgumentException('A provenance bill-of-materials digest must be a SHA-256 value.');
        }
        if ($entryCount < 0 || $expandedBytes < 0) {
            throw new InvalidArgumentException('Provenance material counts cannot be negative.');
        }

        return new self([
            'format' => self::FORMAT,
            'build_type' => self::BUILD_TYPE,
            'builder' => ['name' => self::BUILDER_NAME, 'version' => self::BUILDER_VERSION],
            'subject' => [
                'name' => $manifest->identifier()->value(),
                'version' => (string) $manifest->version(),
                'extension_type' => $manifest->type()->value,
                'manifest_schema' => $manifest->schemaVersion(),
            ],
            'materials' => [
                'sbom_path' => PackageBillOfMaterials::PATH,
                'sbom_format' => 'CycloneDX/' . PackageBillOfMaterials::SPEC_VERSION,
                'sbom_sha256' => $sbomSha256,
                'entry_count' => $entryCount,
                'expanded_bytes' => $expandedBytes,
            ],
            'invocation' => [
                'reproducible' => true,
                'entry_epoch' => 315_532_800,
                'entry_mode' => '0100644',
                'compression' => 'store',
            ],
        ]);
    }

    /**
     * Freeze an already-validated statement.
     *
     * @param  array<string, mixed>  $statement  Complete statement in canonical key order.
     *
     * @since  2.0.0
     */
    private function __construct(public array $statement)
    {
    }

    /**
     * Decode a provenance statement read from a package, rejecting every unknown or missing key.
     *
     * Unlike the bill of materials this format is Kumwe's own, so it is parsed strictly in both
     * directions: an unrecognised top-level key is a refusal rather than something to carry forward,
     * which is what keeps the statement's meaning from drifting between builders.
     *
     * @param   string  $json  Raw statement bytes read from the package.
     *
     * @return  self  Validated statement.
     *
     * @throws  InvalidArgumentException  When the statement is oversized, is not an object of exactly the
     *          declared keys, declares an unsupported format or build type, or holds a malformed section.
     * @throws  JsonException  When the JSON is malformed or too deeply nested.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('The package provenance statement exceeds 16 KiB.');
        }
        $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The package provenance statement must be a JSON object.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['build_type', 'builder', 'format', 'invocation', 'materials', 'subject']) {
            throw new InvalidArgumentException(
                'The package provenance statement contains an unknown or missing key.',
            );
        }
        if (($value['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('The package provenance statement format is unsupported.');
        }
        if (!is_string($value['build_type'] ?? null)) {
            throw new InvalidArgumentException('The package provenance build type must be a string.');
        }
        foreach (['builder', 'subject', 'materials', 'invocation'] as $section) {
            $content = $value[$section] ?? null;
            if (!is_array($content) || array_is_list($content)) {
                throw new InvalidArgumentException(sprintf(
                    'The package provenance section %s must be a JSON object.',
                    $section,
                ));
            }
        }

        /** @var array<string, mixed> $value */
        return new self($value);
    }

    /**
     * Check the statement against the package it travels in.
     *
     * @param   ExtensionManifest  $manifest    Manifest the package actually carries.
     * @param   string             $sbomSha256  SHA-256 of the bill of materials found beside it.
     *
     * @return  list<string>  Sorted mismatch descriptions; empty when the statement describes this package.
     *
     * @since   2.0.0
     */
    public function reconcile(ExtensionManifest $manifest, string $sbomSha256): array
    {
        $findings = [];
        $subject = $this->section('subject');
        if (($subject['name'] ?? null) !== $manifest->identifier()->value()) {
            $findings[] = 'The provenance statement names a different extension than the manifest.';
        }
        if (($subject['version'] ?? null) !== (string) $manifest->version()) {
            $findings[] = 'The provenance statement names a different version than the manifest.';
        }
        if (($subject['extension_type'] ?? null) !== $manifest->type()->value) {
            $findings[] = 'The provenance statement names a different extension type than the manifest.';
        }
        $materials = $this->section('materials');
        $claimed = $materials['sbom_sha256'] ?? null;
        if (!is_string($claimed) || !hash_equals($claimed, $sbomSha256)) {
            $findings[] = 'The provenance statement records a different bill-of-materials digest.';
        }
        if (($materials['sbom_path'] ?? null) !== PackageBillOfMaterials::PATH) {
            $findings[] = 'The provenance statement names a bill of materials the package does not carry.';
        }
        sort($findings, SORT_STRING);

        return $findings;
    }

    /**
     * Report the builder identity the statement asserts, for display beside the verification result.
     *
     * @return  string  `name@version`, or `unknown` when the statement names neither.
     *
     * @since   2.0.0
     */
    public function builderReference(): string
    {
        $builder = $this->section('builder');
        $name = $builder['name'] ?? null;
        $version = $builder['version'] ?? null;
        if (!is_string($name) || $name === '') {
            return 'unknown';
        }

        return $name . '@' . (is_string($version) && $version !== '' ? $version : 'unknown');
    }

    /**
     * Encode the statement deterministically for embedding in a package.
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
            $this->statement,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Read one already-validated object section of the statement.
     *
     * @param   string  $name  Section key, one of the four the strict parse proved to be objects.
     *
     * @return  array<string, mixed>  The section as decoded.
     *
     * @since   2.0.0
     */
    private function section(string $name): array
    {
        $section = $this->statement[$name] ?? [];

        /** @var array<string, mixed> $section */
        $section = is_array($section) ? $section : [];

        return $section;
    }
}
