<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use Ramsey\Uuid\Uuid;

/**
 * The CycloneDX bill of materials an extension package carries about its own contents.
 *
 * CycloneDX 1.6 was chosen over SPDX for three reasons that are specific to this repository rather
 * than to the formats in the abstract. The release pipeline already emits CycloneDX for the core
 * images and the source tree, so an operator correlating a package against the core it runs on reads
 * one format instead of two. Its JSON serialization is small enough to embed in every package and to
 * store on a release row, where an SPDX document of the same fidelity is several times the size. And
 * its component model has a first-class `file` type with a hash list, which is exactly the unit of
 * inventory an extension has: the deterministic builder refuses a packaged `vendor/` or
 * `node_modules/` tree, so bundled third-party code arrives as vendored source files, and a per-file
 * digest is the only honest way to describe it.
 *
 * The document lives inside the package as `kumwe.sbom.json`, not beside it. That is what makes it
 * evidence rather than an assertion: the package digest covers it, and the detached Ed25519 signature
 * covers the package digest, so the publisher's signature already vouches for the inventory without a
 * second signature format. The document necessarily excludes itself and its provenance sibling from
 * its own component list — a document cannot contain its own digest — and admission verifies that
 * exclusion rather than assuming it, by requiring every other entry to be listed with a matching
 * digest and every listed component to exist.
 *
 * No timestamp is emitted. CycloneDX makes `metadata.timestamp` optional, and a build clock would
 * defeat the byte-reproducibility the package builder exists to provide.
 *
 * @since  2.0.0
 */
final readonly class PackageBillOfMaterials
{
    /**
     * Package path the bill of materials is carried at.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PATH = 'kumwe.sbom.json';

    /**
     * CycloneDX specification version emitted and accepted.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SPEC_VERSION = '1.6';

    /**
     * Largest bill of materials expanded during admission, covering the 4096-entry package limit.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_BYTES = 4_194_304;

    /**
     * Build the document for one package from its manifest and its per-entry digests.
     *
     * @param   ExtensionManifest      $manifest      Strict parsed manifest naming the component this
     *          bill of materials describes.
     * @param   array<string, string>  $entryDigests  Lowercase SHA-256 by package path, covering every
     *          packaged file except the two attestation documents.
     *
     * @return  self  Document ready to encode into the package.
     *
     * @throws  InvalidArgumentException  When a digest is not a lowercase SHA-256 value.
     *
     * @since   2.0.0
     */
    public static function forPackage(ExtensionManifest $manifest, array $entryDigests): self
    {
        ksort($entryDigests, SORT_STRING);
        $components = [];
        $rolling = '';
        foreach ($entryDigests as $path => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('A package bill-of-materials digest must be a SHA-256 value.');
            }
            $rolling = hash('sha256', $rolling . $path . ':' . $digest);
            $components[] = [
                'type' => 'file',
                'bom-ref' => 'file:' . $path,
                'name' => $path,
                'hashes' => [['alg' => 'SHA-256', 'content' => $digest]],
            ];
        }
        $identifier = $manifest->identifier()->value();
        $version = (string) $manifest->version();
        $purl = sprintf('pkg:generic/%s@%s', $identifier, rawurlencode($version));
        $serial = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'kumwe-extension-sbom:' . $identifier . ':' . $version . ':' . $rolling,
        )->toString();

        return new self([
            'bomFormat' => 'CycloneDX',
            'specVersion' => self::SPEC_VERSION,
            'serialNumber' => 'urn:uuid:' . $serial,
            'version' => 1,
            'metadata' => [
                'component' => [
                    'type' => 'application',
                    'bom-ref' => $purl,
                    'name' => $identifier,
                    'version' => $version,
                    'purl' => $purl,
                ],
                'tools' => [
                    'components' => [[
                        'type' => 'application',
                        'name' => PackageProvenance::BUILDER_NAME,
                        'version' => PackageProvenance::BUILDER_VERSION,
                    ]],
                ],
                'properties' => [
                    ['name' => 'kumwe:extension_type', 'value' => $manifest->type()->value],
                    ['name' => 'kumwe:manifest_schema', 'value' => (string) $manifest->schemaVersion()],
                    ['name' => 'kumwe:excluded_paths', 'value' => self::PATH . ',' . PackageProvenance::PATH],
                ],
            ],
            'components' => $components,
            'dependencies' => self::dependencyGraph($manifest, $purl),
        ]);
    }

    /**
     * Freeze an already-shaped CycloneDX document.
     *
     * @param  array<string, mixed>  $document  Complete document in canonical key order.
     *
     * @since  2.0.0
     */
    private function __construct(public array $document)
    {
    }

    /**
     * Decode a bill of materials read from a package and refuse a shape admission cannot judge.
     *
     * The parse is deliberately strict about the few fields verification depends on and deliberately
     * tolerant of everything else CycloneDX allows, so a document enriched by a future builder still
     * installs. What it will not accept is a document that is not CycloneDX, is a specification version
     * this code has not been written against, or carries a component list it cannot read.
     *
     * @param   string  $json  Raw document bytes read from the package.
     *
     * @return  self  Validated document.
     *
     * @throws  InvalidArgumentException  When the document is oversized, is not a CycloneDX object of a
     *          supported specification version, or holds an unreadable component.
     * @throws  JsonException  When the JSON is malformed or too deeply nested.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('The package bill of materials exceeds 4 MiB.');
        }
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The package bill of materials must be a JSON object.');
        }
        if (($value['bomFormat'] ?? null) !== 'CycloneDX') {
            throw new InvalidArgumentException('The package bill of materials must declare bomFormat CycloneDX.');
        }
        if (($value['specVersion'] ?? null) !== self::SPEC_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'The package bill of materials must declare CycloneDX specification version %s.',
                self::SPEC_VERSION,
            ));
        }
        $components = $value['components'] ?? null;
        if (!is_array($components) || !array_is_list($components)) {
            throw new InvalidArgumentException('The package bill of materials must carry a component list.');
        }

        /** @var array<string, mixed> $value */
        return new self($value);
    }

    /**
     * Report the file components the document claims, as a digest keyed by package path.
     *
     * Only `file` components participate; anything else CycloneDX permits is another kind of claim and
     * is not something a package's own bytes can confirm.
     *
     * @return  array<string, string>  Lowercase SHA-256 by package path, sorted by path.
     *
     * @throws  InvalidArgumentException  When a file component names no path or no SHA-256 digest, or
     *          names the same path twice.
     *
     * @since   2.0.0
     */
    public function fileDigests(): array
    {
        $components = $this->document['components'] ?? [];
        $digests = [];
        if (!is_array($components)) {
            return $digests;
        }
        foreach ($components as $component) {
            if (!is_array($component) || ($component['type'] ?? null) !== 'file') {
                continue;
            }
            $name = $component['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('A bill-of-materials file component names no path.');
            }
            if (isset($digests[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'The bill of materials lists %s more than once.',
                    $name,
                ));
            }
            $digests[$name] = $this->sha256Of($component, $name);
        }
        ksort($digests, SORT_STRING);

        return $digests;
    }

    /**
     * Compare the document's inventory against the digests actually computed from the package.
     *
     * @param   array<string, string>  $entryDigests  Lowercase SHA-256 by package path for every packaged
     *          file except the two attestation documents.
     *
     * @return  list<string>  Sorted mismatch descriptions; empty when the inventory is exact.
     *
     * @throws  InvalidArgumentException  When a file component names no path or no SHA-256 digest.
     *
     * @since   2.0.0
     */
    public function reconcile(array $entryDigests): array
    {
        $claimed = $this->fileDigests();
        ksort($entryDigests, SORT_STRING);
        $findings = [];
        foreach ($entryDigests as $path => $digest) {
            $listed = $claimed[$path] ?? null;
            if ($listed === null) {
                $findings[] = sprintf('The bill of materials does not list packaged file %s.', $path);
                continue;
            }
            if (!hash_equals($listed, $digest)) {
                $findings[] = sprintf('The bill of materials records a different digest for %s.', $path);
            }
        }
        foreach (array_keys($claimed) as $path) {
            if (!isset($entryDigests[$path])) {
                $findings[] = sprintf('The bill of materials lists %s, which the package does not carry.', $path);
            }
        }
        sort($findings, SORT_STRING);

        return $findings;
    }

    /**
     * Report how many file components the document inventories.
     *
     * @return  int  Component count, used by the operator surface as the inventory's size.
     *
     * @throws  InvalidArgumentException  When a file component names no path or no SHA-256 digest.
     *
     * @since   2.0.0
     */
    public function componentCount(): int
    {
        return count($this->fileDigests());
    }

    /**
     * Encode the document deterministically for embedding in a package.
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
            $this->document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Read the single SHA-256 hash a file component must carry.
     *
     * @param   array<mixed>  $component  Decoded CycloneDX component object.
     * @param   string        $name       Component name quoted in the failure message.
     *
     * @return  string  Lowercase hexadecimal SHA-256 digest.
     *
     * @throws  InvalidArgumentException  When no readable SHA-256 hash entry is present.
     *
     * @since   2.0.0
     */
    private function sha256Of(array $component, string $name): string
    {
        $hashes = $component['hashes'] ?? null;
        if (is_array($hashes)) {
            foreach ($hashes as $hash) {
                if (!is_array($hash) || ($hash['alg'] ?? null) !== 'SHA-256') {
                    continue;
                }
                $content = $hash['content'] ?? null;
                if (is_string($content) && preg_match('/^[a-f0-9]{64}$/D', $content) === 1) {
                    return $content;
                }
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Bill-of-materials component %s carries no lowercase SHA-256 hash.',
            $name,
        ));
    }

    /**
     * Express the manifest's declared extension dependencies as a CycloneDX dependency graph.
     *
     * A Kumwe dependency names a version constraint rather than a resolved version, so the constraint is
     * carried in the reference instead of being invented as a version — an SBOM that claimed a resolved
     * version it never saw would be worse than one that admits it is describing a requirement.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose declared dependencies are being described.
     * @param   string             $purl      Package URL of the component the graph is rooted at.
     *
     * @return  list<array{ref: string, dependsOn: list<string>}>  Root entry, plus one leaf per dependency.
     *
     * @since   2.0.0
     */
    private static function dependencyGraph(ExtensionManifest $manifest, string $purl): array
    {
        $references = [];
        foreach ($manifest->dependencies() as $dependency) {
            $references[] = sprintf(
                'requires:%s@%s',
                $dependency->extension()->value(),
                (string) $dependency->constraint(),
            );
        }
        sort($references, SORT_STRING);
        $graph = [['ref' => $purl, 'dependsOn' => $references]];
        foreach ($references as $reference) {
            $graph[] = ['ref' => $reference, 'dependsOn' => []];
        }

        return $graph;
    }
}
