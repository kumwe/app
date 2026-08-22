<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
/**
 * Proves the extension-contract gate is wired into the build and fails in the direction it claims to.
 *
 * A freeze nobody runs is a document. This asserts that `composer qa` runs the check, that the check
 * passes on the committed contract, and that widening a frozen generation without recording the change
 * stops the build — which is the whole point of the digest.
 *
 * @since  2.0.0
 */
final class ExtensionContractGateTest extends TestCase
{
    /**
     * Absolute path to the repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root the gate is run from.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Require the committed contract to pass the check it ships with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedContractPassesItsOwnCheck(): void
    {
        $result = $this->runCheck();

        self::assertSame(
            0,
            $result['status'],
            "composer extension:contract must pass on the committed contract:\n" . $result['output'],
        );
        self::assertStringContainsString('Kumwe extension contract verified', $result['output']);
    }

    /**
     * Require a frozen generation that quietly gains a promise to stop the build.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWideningAFrozenGenerationWithoutRecordingItFailsTheBuild(): void
    {
        $document = $this->decodeJson('docs/extension-contract/generations.json');
        $generations = $document['manifest_generations'];
        self::assertIsArray($generations);
        self::assertIsArray($generations[0]);
        $keys = $generations[0]['manifest_keys'];
        self::assertIsArray($keys);
        $keys[] = ['key' => 'an_undeclared_widening', 'status' => 'interpreted'];
        $generations[0]['manifest_keys'] = $keys;
        $document['manifest_generations'] = $generations;

        $path = $this->writeTemporaryDocument($document);

        try {
            $result = $this->runCheck($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('frozen surface', $result['output']);
    }

    /**
     * Require a self-consistent retained digest to fail when it omits keys the live parser accepts.
     *
     * A digest proves only that a document did not change accidentally. This case deliberately rewrites
     * both a generation record and its digest to the incomplete historical schema-4 shape, proving the
     * independent parser-parity check still catches multilingual content and unit-converter drift.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedGenerationMustMatchTheLiveParserGrammar(): void
    {
        $document = $this->decodeJson('docs/extension-contract/generations.json');
        $generations = $document['manifest_generations'];
        self::assertIsArray($generations);
        self::assertIsArray($generations[3]);

        $generation = $generations[3];
        self::assertSame('manifest-4', $generation['id'] ?? null);
        self::assertIsArray($generation['contribution_keys']);
        self::assertIsArray($generation['integration_keys']);
        $generation['contribution_keys'] = array_values(array_filter(
            $generation['contribution_keys'],
            static fn (mixed $key): bool => $key !== 'content',
        ));
        $generation['integration_keys'] = array_values(array_filter(
            $generation['integration_keys'],
            static fn (mixed $key): bool => $key !== 'unit_converters',
        ));
        unset($generation['content_keys'], $generation['surface_digest']);
        $canonical = $this->canonical($generation);
        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        $generation['surface_digest'] = hash('sha256', $encoded);
        $generations[3] = $generation;
        $document['manifest_generations'] = $generations;

        $path = $this->writeTemporaryDocument($document);
        try {
            $result = $this->runCheck($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('the live parser accepts', $result['output']);
        self::assertStringContainsString('content_keys', $result['output']);
    }

    /**
     * Require a withdrawn type that is quietly reinstated to stop the build.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordingAStillPresentTypeAsWithdrawnFailsTheBuild(): void
    {
        $document = $this->decodeJson('docs/extension-contract/generations.json');
        $withdrawn = $document['withdrawn'];
        self::assertIsArray($withdrawn);
        $withdrawn[] = [
            'id' => 'still-present',
            'type' => 'Kumwe\\App\\Extension\\Contribution\\ExtensionContributionRegistrar',
            'kind' => 'interface',
            'withdrawn_in' => '0000000',
            'reason' => 'It is not withdrawn at all, which is what this proves the check notices.',
            'replacement' => null,
        ];
        $document['withdrawn'] = $withdrawn;

        $path = $this->writeTemporaryDocument($document);

        try {
            $result = $this->runCheck($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('recorded as withdrawn but still exists', $result['output']);
    }

    /**
     * Require the quality gate to run the contract check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheQualityGateRunsTheContractCheck(): void
    {
        $composer = $this->decodeJson('composer.json');
        $scripts = $composer['scripts'];
        self::assertIsArray($scripts);
        self::assertSame('php tools/verify-extension-contract.php', $scripts['extension:contract'] ?? null);
        self::assertIsArray($scripts['qa'] ?? null);
        self::assertContains('@extension:contract', $scripts['qa']);
    }

    /**
     * Require the author-facing documentation to point at the two machine-readable documents.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentationPointsAtTheContractItDescribes(): void
    {
        $readme = file_get_contents($this->root . '/docs/extension-contract/README.md');
        self::assertIsString($readme);
        self::assertStringContainsString('classification.json', $readme);
        self::assertStringContainsString('generations.json', $readme);

        $extensions = file_get_contents($this->root . '/docs/extensions.md');
        self::assertIsString($extensions);
        self::assertStringContainsString('extension-contract/README.md', $extensions);
    }

    /**
     * Run the contract check, optionally against a substitute generations document.
     *
     * @param   ?string  $generations  Absolute path to a substitute document, or null for the committed one.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function runCheck(?string $generations = null): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-extension-contract.php'),
        );
        if ($generations !== null) {
            $command .= ' ' . escapeshellarg('--generations=' . $generations);
        }

        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Write a modified contract document to a private temporary file.
     *
     * @param   array<string, mixed>  $document  Document to encode.
     *
     * @return  string  Absolute path to the temporary file.
     *
     * @since   2.0.0
     */
    private function writeTemporaryDocument(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-extension-contract-');
        self::assertIsString($path);
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        file_put_contents($path, $encoded);

        return $path;
    }

    /**
     * Decode one repository document as JSON.
     *
     * @param   string  $relative  Repository-relative path.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private function decodeJson(string $relative): array
    {
        $raw = file_get_contents($this->root . '/' . $relative);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Sort every object key recursively in the same semantic form the generation verifier digests.
     *
     * @param   mixed  $value  Decoded JSON value.
     *
     * @return  mixed  Canonical value with list order retained and object keys sorted.
     *
     * @since   2.0.0
     */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $mapped = array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        if (!array_is_list($mapped)) {
            ksort($mapped, SORT_STRING);
        }

        return $mapped;
    }
}
