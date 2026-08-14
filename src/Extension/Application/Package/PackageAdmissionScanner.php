<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use RuntimeException;

/**
 * The admission gate that reads a package's own code and attestations before anything is unpacked.
 *
 * Installation already refused unsafe archives, non-strict manifests and untrusted signatures. What it
 * never did was look at the packaged PHP, even though the SDK has scanned it since the SDK existed, so
 * a signed package could carry code that has never been parsed by anything until the moment its
 * provider was resolved on a live site. This closes that, and it does it in one bounded pass: entries
 * are expanded one at a time through `ArchiveContentReader`, digested for the bill of materials, and
 * checked by `PackageCodeConformance` — the same checks the SDK reports, so the two answers agree.
 *
 * Two failure semantics meet here and they are deliberately different.
 *
 * Attestations fail closed regardless of mode. `kumwe.sbom.json` and `kumwe.provenance.json` travel
 * inside the package bytes the publisher's signature covers, so a document that is present and does
 * not reconcile is not a warning about code quality — it is a package whose contents disagree with
 * what its own builder recorded, and it is refused. A package carrying neither document is recorded as
 * `Absent` and admitted, because packages built before attestations shipped must keep installing; the
 * Extensions screen shows the difference so an operator can tell an old package from a described one.
 *
 * Code conformance fails according to `PackageConformanceMode`, and only two findings are ever
 * blocking: PHP that does not parse, and a manifest naming a class, asset or template the package does
 * not carry. Both describe a package that is already broken and whose breakage would otherwise surface
 * as a fatal error on a live request after publication. Everything else the scan can find — a missing
 * `strict_types` declaration, an unresolved authoring marker, an absent README — is recorded as
 * advisory, because refusing a third-party package over house style would be a policy this project has
 * no standing to enforce on other people's code.
 *
 * @since  2.0.0
 */
final readonly class PackageAdmissionScanner
{
    /**
     * Wire the scanner to the content port, the shared checks and this installation's posture.
     *
     * @param  ArchiveContentReader    $contents     Port expanding one packaged entry at a time.
     * @param  PackageCodeConformance  $conformance  Shared static checks, also used by the SDK runner.
     * @param  PackageConformanceMode  $mode         Posture the code scan is applied under.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ArchiveContentReader $contents,
        private PackageCodeConformance $conformance,
        private PackageConformanceMode $mode = PackageConformanceMode::Enforce,
    ) {
    }

    /**
     * Admit or refuse one staged package, and describe what was established either way it is admitted.
     *
     * @param   string             $archiveFile  Path of the private archive snapshot being installed.
     * @param   ExtensionManifest  $manifest     Strict parsed manifest already read from that archive.
     *
     * @return  PackageAdmissionReport  What the scan found, for the release row, the audit trail and the
     *          Extensions screen.
     *
     * @throws  NonConformingPackage  When an attestation document is present and does not describe this
     *          package, or a blocking code finding is raised under `Enforce`.
     * @throws  InvalidArgumentException  When the archive cannot be opened for reading.
     * @throws  RuntimeException  When a packaged entry cannot be read completely.
     *
     * @since   2.0.0
     */
    public function scan(string $archiveFile, ExtensionManifest $manifest): PackageAdmissionReport
    {
        $digests = [];
        $paths = [];
        $sbomJson = null;
        $provenanceJson = null;
        $violations = [];
        $syntaxFailed = false;
        $scanning = $this->mode !== PackageConformanceMode::Off;

        foreach ($this->contents->contents($archiveFile) as $path => $entry) {
            $paths[] = $path;
            if ($path === PackageBillOfMaterials::PATH) {
                $sbomJson = $entry;
                continue;
            }
            if ($path === PackageProvenance::PATH) {
                $provenanceJson = $entry;
                continue;
            }
            $digests[$path] = hash('sha256', $entry);
            if (!$scanning) {
                continue;
            }
            if ($this->conformance->isPhpPath($path)) {
                $found = $this->conformance->phpViolations($path, $entry);
                $syntaxFailed = $syntaxFailed || $this->hasSyntaxFailure($found);
                $violations = [...$violations, ...$found];
            }
            if ($this->conformance->isTextPath($path)) {
                $violations = [...$violations, ...$this->conformance->markerViolations($path, $entry)];
            }
        }

        $references = $scanning ? $this->conformance->referenceViolations($manifest, $paths) : [];
        $violations = [...$violations, ...$references];
        $blocking = [];
        $advisory = [];
        foreach ($violations as $violation) {
            if (
                str_starts_with($violation, 'PHP syntax failure')
                || str_starts_with($violation, 'Manifest reference')
            ) {
                $blocking[] = $violation;
                continue;
            }
            $advisory[] = $violation;
        }
        if ($scanning && !in_array('README.md', $paths, true)) {
            $advisory[] = 'The package carries no README.md for operators to read.';
        }
        sort($blocking, SORT_STRING);
        sort($advisory, SORT_STRING);

        $sbom = $this->verifiedBillOfMaterials($sbomJson, $digests);
        $provenance = $this->verifiedProvenance($provenanceJson, $manifest, $sbom['sha256']);
        if ($sbom['state'] === PackageAttestationState::Absent && $provenanceJson !== null) {
            throw new NonConformingPackage(
                'The extension package carries a provenance statement but no bill of materials to bind it to.',
            );
        }
        if ($blocking !== [] && $this->mode === PackageConformanceMode::Enforce) {
            throw new NonConformingPackage(sprintf(
                'The extension package failed install-time code conformance: %s',
                implode(' ', $blocking),
            ));
        }

        return new PackageAdmissionReport(
            $sbom['state'],
            $sbom['sha256'],
            $sbom['components'],
            $sbom['document'],
            $provenance['state'],
            $provenance['sha256'],
            $provenance['builder'],
            $provenance['document'],
            $this->mode,
            $this->stateOf($scanning, $blocking),
            $this->checksOf($scanning, $syntaxFailed, $references, $advisory, $paths),
            $blocking,
            $advisory,
        );
    }

    /**
     * Reconcile the packaged bill of materials against the digests just computed from the package.
     *
     * @param   ?string                $json     Raw document bytes, or null when the package carries none.
     * @param   array<string, string>  $digests  Lowercase SHA-256 by package path, excluding attestations.
     *
     * @return  array{state: PackageAttestationState, sha256: ?string, components: int,
     *          document: ?array<string, mixed>}  Verified result, or an absent one.
     *
     * @throws  NonConformingPackage  When the document is unreadable or disagrees with the package.
     *
     * @since   2.0.0
     */
    private function verifiedBillOfMaterials(?string $json, array $digests): array
    {
        if ($json === null) {
            return [
                'state' => PackageAttestationState::Absent,
                'sha256' => null,
                'components' => 0,
                'document' => null,
            ];
        }
        try {
            $document = PackageBillOfMaterials::fromJson($json);
            $findings = $document->reconcile($digests);
            $components = $document->componentCount();
        } catch (JsonException | InvalidArgumentException $failure) {
            throw new NonConformingPackage(
                'The packaged bill of materials could not be read: ' . $failure->getMessage(),
                0,
                $failure,
            );
        }
        if ($findings !== []) {
            throw new NonConformingPackage(
                'The packaged bill of materials does not describe this package: ' . implode(' ', $findings),
            );
        }

        return [
            'state' => PackageAttestationState::Verified,
            'sha256' => hash('sha256', $json),
            'components' => $components,
            'document' => $document->document,
        ];
    }

    /**
     * Reconcile the packaged provenance statement against the manifest and the bill of materials.
     *
     * @param   ?string            $json        Raw statement bytes, or null when the package carries none.
     * @param   ExtensionManifest  $manifest    Manifest the package actually carries.
     * @param   ?string            $sbomSha256  Digest of the verified bill of materials, or null.
     *
     * @return  array{state: PackageAttestationState, sha256: ?string, builder: ?string,
     *          document: ?array<string, mixed>}  Verified result, or an absent one.
     *
     * @throws  NonConformingPackage  When the statement is unreadable or describes another package.
     *
     * @since   2.0.0
     */
    private function verifiedProvenance(?string $json, ExtensionManifest $manifest, ?string $sbomSha256): array
    {
        if ($json === null || $sbomSha256 === null) {
            return [
                'state' => PackageAttestationState::Absent,
                'sha256' => null,
                'builder' => null,
                'document' => null,
            ];
        }
        try {
            $statement = PackageProvenance::fromJson($json);
            $findings = $statement->reconcile($manifest, $sbomSha256);
        } catch (JsonException | InvalidArgumentException $failure) {
            throw new NonConformingPackage(
                'The packaged provenance statement could not be read: ' . $failure->getMessage(),
                0,
                $failure,
            );
        }
        if ($findings !== []) {
            throw new NonConformingPackage(
                'The packaged provenance statement does not describe this package: ' . implode(' ', $findings),
            );
        }

        return [
            'state' => PackageAttestationState::Verified,
            'sha256' => hash('sha256', $json),
            'builder' => $statement->builderReference(),
            'document' => $statement->statement,
        ];
    }

    /**
     * Name the outcome the release row records for the code scan.
     *
     * @param   bool          $scanning  Whether the scan ran at all.
     * @param   list<string>  $blocking  Blocking findings the mode may or may not have admitted.
     *
     * @return  string  `skipped`, `warned`, or `passed`.
     *
     * @since   2.0.0
     */
    private function stateOf(bool $scanning, array $blocking): string
    {
        if (!$scanning) {
            return 'skipped';
        }

        return $blocking === [] ? 'passed' : 'warned';
    }

    /**
     * Summarise the scan as the named checks the operator surface renders.
     *
     * @param   bool          $scanning      Whether the scan ran at all.
     * @param   bool          $syntaxFailed  Whether any packaged PHP file failed to parse.
     * @param   list<string>  $references    Manifest-reference violations found.
     * @param   list<string>  $advisory      Advisory findings recorded.
     * @param   list<string>  $paths         Every path the package carries.
     *
     * @return  array<string, bool>  Check name to whether it held; empty when no scan ran.
     *
     * @since   2.0.0
     */
    private function checksOf(
        bool $scanning,
        bool $syntaxFailed,
        array $references,
        array $advisory,
        array $paths,
    ): array {
        if (!$scanning) {
            return [];
        }

        return [
            'static_php_syntax' => !$syntaxFailed,
            'manifest_references' => $references === [],
            'strict_types' => !$this->hasPrefix($advisory, 'PHP file'),
            'complete_sources' => !$this->hasPrefix($advisory, 'Unresolved marker'),
            'authoring_readme' => in_array('README.md', $paths, true),
        ];
    }

    /**
     * Report whether a violation list contains a PHP parse failure.
     *
     * @param   list<string>  $violations  Violations produced for one file.
     *
     * @return  bool  True when the file did not parse.
     *
     * @since   2.0.0
     */
    private function hasSyntaxFailure(array $violations): bool
    {
        return $this->hasPrefix($violations, 'PHP syntax failure');
    }

    /**
     * Report whether any entry of a list starts with a stable category prefix.
     *
     * @param   list<string>  $values  Findings to scan.
     * @param   string        $prefix  Category prefix.
     *
     * @return  bool  True when at least one entry belongs to the category.
     *
     * @since   2.0.0
     */
    private function hasPrefix(array $values, string $prefix): bool
    {
        foreach ($values as $value) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
