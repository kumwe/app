<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RoadmapLifecycleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheRoadmapLedgerHoldsForwardWorkOnly(): void
    {
        $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json');

        self::assertSame(
            0,
            $result['status'],
            "composer roadmap:check must pass on the committed ledger:\n" . $result['output'],
        );
        self::assertStringContainsString('Kumwe roadmap verified', $result['output']);
    }

    public function testTheVerifierRefusesAClosedFindingAndSaysWhereItBelongs(): void
    {
        $ledger = $this->decodeJson('docs/roadmap/findings.json');
        $findings = $ledger['findings'];
        self::assertIsArray($findings);
        self::assertNotEmpty($findings);

        $reintroduced = $findings[0];
        self::assertIsArray($reintroduced);
        $reintroduced['id'] = 'GM-TEST-01';
        $reintroduced['state'] = 'closed';
        $ledger['findings'] = [...$findings, $reintroduced];

        $path = $this->writeTemporaryLedger($ledger);

        try {
            $result = $this->runVerifier($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status'], 'A reintroduced closed finding must fail the check.');
        self::assertStringContainsString('GM-TEST-01', $result['output']);
        self::assertStringContainsString('CHANGELOG.md', $result['output']);
    }

    public function testTheVerifierRefusesAMalformedLedger(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-roadmap-');
        self::assertIsString($path);
        file_put_contents($path, '{"findings": [');

        try {
            $result = $this->runVerifier($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('well-formed JSON', $result['output']);
    }

    public function testTheMachineReadableCompanionsParse(): void
    {
        $findings = $this->decodeJson('docs/roadmap/findings.json');
        $capacity = $this->decodeJson('docs/roadmap/capacity-contract.json');

        self::assertNotSame([], $findings);
        self::assertNotSame([], $capacity);

        $states = $findings['states'];
        self::assertIsArray($states);
        self::assertNotContains(
            'closed',
            $states,
            'The ledger holds forward work only, so "closed" must not be an allowed state.',
        );
    }

    public function testTheQualityGateRunsTheLifecycleCheck(): void
    {
        $composer = $this->decodeJson('composer.json');
        $scripts = $composer['scripts'];
        self::assertIsArray($scripts);
        self::assertSame('php tools/verify-roadmap.php', $scripts['roadmap:check'] ?? null);
        self::assertIsArray($scripts['qa'] ?? null);
        self::assertContains('@roadmap:check', $scripts['qa']);
    }

    public function testTheTwoDocumentsPointAtEachOther(): void
    {
        $changelog = $this->contents('CHANGELOG.md');
        $roadmap = $this->contents('docs/roadmap/README.md');
        $status = $this->contents('docs/roadmap/STATUS.md');
        $agents = $this->contents('AGENTS.md');

        self::assertStringContainsString('docs/roadmap/README.md', $changelog);
        self::assertStringContainsString('docs/roadmap/STATUS.md', $changelog);
        self::assertStringContainsString('## [Unreleased]', $changelog);

        self::assertStringContainsString('## How this document moves', $roadmap);
        self::assertStringContainsString('CHANGELOG.md', $roadmap);
        self::assertStringContainsString('CHANGELOG.md', $status);
        self::assertStringContainsString('CHANGELOG.md', $agents);
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runVerifier(string $ledger): array
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-roadmap.php'),
            escapeshellarg('--findings=' . $ledger),
        );

        $lines = [];
        $status = 0;
        exec($command, $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    private function writeTemporaryLedger(array $ledger): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-roadmap-');
        self::assertIsString($path);
        $encoded = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        file_put_contents($path, $encoded);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $path): array
    {
        $decoded = json_decode($this->contents($path), true);
        self::assertIsArray($decoded, sprintf('%s is not well-formed JSON.', $path));

        return $decoded;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
