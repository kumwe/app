<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Infrastructure;

use InvalidArgumentException;
use Kumwe\App\OpenApi\Infrastructure\RestMachineContractGenerationLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that REST compatibility evidence is complete and append-only.
 *
 * @since  2.0.0
 */
#[CoversClass(RestMachineContractGenerationLedger::class)]
final class RestMachineContractGenerationLedgerTest extends TestCase
{
    /**
     * Keep an already retained byte-identical generation unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeepsAnIdenticalRetainedGeneration(): void
    {
        $candidate = $this->candidate('1.0.0');
        $ledger = $this->ledger($candidate);

        self::assertSame(
            $ledger,
            RestMachineContractGenerationLedger::retain($ledger, array_reverse($candidate, true)),
        );
    }

    /**
     * Refuse changed bytes under a generation clients may already have consumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsReplacementBytesUnderARetainedGeneration(): void
    {
        $candidate = $this->candidate('1.0.0');
        $ledger = $this->ledger($candidate);
        $candidate['artifact_sha256'] = str_repeat('b', 64);

        try {
            RestMachineContractGenerationLedger::retain($ledger, $candidate);
            self::fail('A retained REST generation was replaced in place.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    /**
     * Append changed bytes under a successor while preserving prior evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppendsASuccessorWithUniqueRetainedPaths(): void
    {
        $original = $this->candidate('1.0.0');
        $successor = $this->candidate('1.1.0');
        $ledger = RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);

        self::assertSame('1.1.0', $ledger['current']);
        self::assertSame([$original, $successor], $ledger['generations']);
    }

    /**
     * Require every successor input, output, registry, and fixture to have a new path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsSuccessorsThatReuseGenerationOwnedPaths(): void
    {
        $original = $this->candidate('1.0.0');
        foreach (['core', 'artifact', 'problem_registry', 'compatibility_fixture'] as $field) {
            $successor = $this->candidate('1.1.0');
            $successor[$field] = $original[$field];

            try {
                RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);
                self::fail(sprintf('A successor reused the retained %s path.', $field));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must be unique', $exception->getMessage());
            }
        }
    }

    /**
     * Permit a stable route-exclusion allowlist to be referenced across generations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllowsAnUnchangedSharedRouteExclusionFile(): void
    {
        $original = $this->candidate('1.0.0');
        $successor = $this->candidate('1.1.0');

        $ledger = RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);

        self::assertSame($original['route_exclusions'], $successor['route_exclusions']);
        self::assertSame($original['route_exclusions_sha256'], $successor['route_exclusions_sha256']);
        $rows = $ledger['generations'];
        self::assertIsArray($rows);
        self::assertCount(2, $rows);
    }

    /**
     * Refuse changed route exclusions when an earlier row retains the same file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsChangedBytesAtASharedRouteExclusionPath(): void
    {
        $original = $this->candidate('1.0.0');
        $successor = $this->candidate('1.1.0');
        $successor['route_exclusions_sha256'] = str_repeat('c', 64);

        try {
            RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);
            self::fail('A shared route-exclusion file changed bytes across generations.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('changed bytes', $exception->getMessage());
        }
    }

    /**
     * Permit changed route exclusions only when the successor retains a new file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllowsChangedRouteExclusionsAtANewRetainedPath(): void
    {
        $original = $this->candidate('1.0.0');
        $successor = $this->candidate('1.1.0');
        $successor['route_exclusions'] = 'api/openapi/generations/1.1.0/route-exclusions.json';
        $successor['route_exclusions_sha256'] = str_repeat('c', 64);

        $ledger = RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);

        self::assertSame([$original, $successor], $ledger['generations']);
    }

    /**
     * Require the exact finite row schema without missing or invented fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsIncompleteAndExtendedRows(): void
    {
        $original = $this->candidate('1.0.0');
        $incomplete = $this->candidate('1.1.0');
        unset($incomplete['problem_registry_sha256']);
        $extended = $this->candidate('1.1.0');
        $extended['notes'] = 'not a machine-contract field';

        foreach ([$incomplete, $extended] as $candidate) {
            try {
                RestMachineContractGenerationLedger::retain($this->ledger($original), $candidate);
                self::fail('An invalid REST generation row shape was retained.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('invalid shape', $exception->getMessage());
            }
        }
    }

    /**
     * Require lowercase full SHA-256 digests for every retained byte sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMalformedDigests(): void
    {
        $original = $this->candidate('1.0.0');
        foreach (['short', str_repeat('A', 64)] as $invalidDigest) {
            $successor = $this->candidate('1.1.0');
            $successor['compatibility_fixture_sha256'] = $invalidDigest;

            try {
                RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);
                self::fail('A malformed REST generation digest was retained.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('lowercase SHA-256', $exception->getMessage());
            }
        }
    }

    /**
     * Reject retained paths that are absolute, escape, or target the wrong registry root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnsafeAndMisplacedRetainedPaths(): void
    {
        $original = $this->candidate('1.0.0');
        foreach (
            [
                'core' => 'api/openapi/../core.json',
                'problem_registry' => 'api/openapi/generations/1.1.0/problem-details.json',
            ] as $field => $invalidPath
        ) {
            $successor = $this->candidate('1.1.0');
            $successor[$field] = $invalidPath;

            try {
                RestMachineContractGenerationLedger::retain($this->ledger($original), $successor);
                self::fail(sprintf('An unsafe or misplaced %s path was retained.', $field));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('REST generation path', $exception->getMessage());
            }
        }
    }

    /**
     * Reject an append that does not move beyond the ledger current generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsANonSuccessorGeneration(): void
    {
        $original = $this->candidate('2.0.0');

        try {
            RestMachineContractGenerationLedger::retain(
                $this->ledger($original),
                $this->candidate('1.9.0'),
            );
            self::fail('An older REST generation was appended after the current generation.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('not a successor', $exception->getMessage());
        }
    }

    /**
     * Reject an envelope whose current pointer does not name the final row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsALedgerWithAStaleCurrentPointer(): void
    {
        $original = $this->candidate('1.0.0');
        $ledger = $this->ledger($original);
        $ledger['current'] = '1.1.0';

        try {
            RestMachineContractGenerationLedger::retain($ledger, $this->candidate('1.1.0'));
            self::fail('A ledger with a stale current pointer was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('final retained generation', $exception->getMessage());
        }
    }

    /**
     * Build one complete, uniquely retained generation row.
     *
     * @param   string  $generation  Semantic generation name.
     *
     * @return  array<string, mixed>  Candidate row accepted by the ledger boundary.
     *
     * @since   2.0.0
     */
    private function candidate(string $generation): array
    {
        $openApiRoot = 'api/openapi/generations/' . $generation;

        return [
            'generation' => $generation,
            'core' => $openApiRoot . '/core.json',
            'artifact' => $openApiRoot . '/openapi.json',
            'problem_registry' => 'api/problem-details/generations/' . $generation . '.json',
            'route_exclusions' => 'api/openapi/route-exclusions.json',
            'compatibility_fixture' => $openApiRoot . '/compatibility.json',
            'compiler_generation_sha256' => hash('sha256', $generation . ':compiler'),
            'core_sha256' => hash('sha256', $generation . ':core'),
            'artifact_sha256' => hash('sha256', $generation . ':artifact'),
            'problem_registry_sha256' => hash('sha256', $generation . ':problems'),
            'route_exclusions_sha256' => hash('sha256', 'shared-route-exclusions'),
            'compatibility_fixture_sha256' => hash('sha256', $generation . ':fixture'),
        ];
    }

    /**
     * Wrap one retained row in the public ledger envelope.
     *
     * @param   array<string, mixed>  $row  Retained generation row.
     *
     * @return  array<string, mixed>  One-generation ledger.
     *
     * @since   2.0.0
     */
    private function ledger(array $row): array
    {
        return [
            'format' => 'kumwe-rest-machine-contract-generations-v1',
            'current' => $row['generation'],
            'generations' => [$row],
        ];
    }
}
