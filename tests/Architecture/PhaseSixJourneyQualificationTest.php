<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps Phase 6 acceptance bound to joined executable journeys and their real CI owners.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PhaseSixJourneyQualificationTest extends TestCase
{
    /**
     * Repository root used to resolve every source-bound qualification record.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the canonical repository root before qualification sources are inspected.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $root = realpath(dirname(__DIR__, 2));
        self::assertIsString($root);
        $this->root = $root;
    }

    /**
     * Proves no Phase 6 journey step can disappear or be replaced with unbound narrative evidence.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed qualification artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testEveryPhaseSixAcceptancePointIsBoundToExecutableEvidence(): void
    {
        $manifest = $this->manifest();
        self::assertSame('kumwe-kis-phase-six-journey-qualification-v1', $manifest['format']);
        self::assertSame(1, $manifest['schema_version']);
        self::assertSame(6, $manifest['phase']);
        self::assertSame('kis-1.0', $manifest['standard']);

        $expectedAcceptance = [
            'P6-001' => [
                'provision',
                'membership',
                'portal-sign-in',
                'open-work',
                'submit-for-approval',
                'checker-decision',
                'result',
            ],
            'P6-002' => [
                'define',
                'publish',
                'schema-plan',
                'schema-approve-execute',
                'record-create',
                'relations',
                'action',
                'report',
                'history',
            ],
            'P6-003' => [
                'install',
                'activate',
                'use',
                'customize',
                'disable',
                'reactivate',
                'upgrade',
                'fallback',
                'reset',
            ],
            'P6-004' => [
                'component-diagnostics',
                'administrator-diagnostics',
                'portal-diagnostics',
                'keyboard-focus-url-responsive',
                'touch-zoom-contrast-motion-print',
                'no-javascript',
            ],
            'P6-005' => [
                'production-topology',
                'mariadb',
                'mysql',
                'postgresql',
                'backup-restore',
                'security-source',
                'security-dependencies',
                'security-image',
                'security-sbom',
            ],
            'P6-006' => [
                'core-contracts',
                'completed-migrations',
                'graphical-fail-closed',
                'owner-lifecycle',
                'programme-verification',
            ],
            'P6-007' => [
                'gates',
                'unresolved-severity-waivers',
                'evidence-index-residual-p3',
                'continuation-acceptance-github-confirmation',
            ],
        ];
        $requiredKinds = [
            'P6-001' => ['executable-php', 'phpunit', 'playwright'],
            'P6-002' => ['phpunit', 'playwright'],
            'P6-003' => ['phpunit', 'playwright'],
            'P6-004' => ['phpunit', 'playwright'],
            'P6-005' => ['phpunit', 'workflow-step'],
            'P6-006' => ['phpunit'],
            'P6-007' => ['qualification', 'review'],
        ];
        $ledgerWorkItemIds = array_keys($this->indexById($this->phaseWorkItems(6)));
        self::assertSame(
            $ledgerWorkItemIds,
            array_keys($expectedAcceptance),
            'The qualification contract drifted from the canonical Phase 6 ledger.',
        );
        $evidence = $this->indexById($manifest['evidence']);
        $referenced = [];
        $workItemIds = [];

        foreach ($manifest['work_items'] as $workItem) {
            $workItemId = $workItem['id'];
            self::assertIsString($workItemId);
            $workItemIds[] = $workItemId;
            self::assertArrayHasKey($workItemId, $expectedAcceptance);
            self::assertIsString($workItem['journey']);
            self::assertNotSame('', trim($workItem['journey']));
            self::assertSame(
                $expectedAcceptance[$workItemId],
                array_column($workItem['acceptance_points'], 'id'),
                sprintf('%s no longer closes its exact ordered acceptance journey.', $workItemId),
            );

            $actualKinds = [];
            foreach ($workItem['acceptance_points'] as $acceptancePoint) {
                self::assertNotEmpty(
                    $acceptancePoint['evidence'],
                    sprintf('%s/%s has no executable evidence.', $workItemId, $acceptancePoint['id']),
                );
                foreach ($acceptancePoint['evidence'] as $evidenceId) {
                    self::assertIsString($evidenceId);
                    self::assertStringStartsWith(strtolower($workItemId) . '-', $evidenceId);
                    self::assertArrayHasKey(
                        $evidenceId,
                        $evidence,
                        sprintf(
                            '%s/%s references unknown evidence %s.',
                            $workItemId,
                            $acceptancePoint['id'],
                            $evidenceId,
                        ),
                    );
                    self::assertIsString($evidence[$evidenceId]['kind']);
                    $actualKinds[$evidence[$evidenceId]['kind']] = true;
                    $referenced[$evidenceId] = true;
                }
            }

            foreach ($requiredKinds[$workItemId] as $requiredKind) {
                self::assertArrayHasKey(
                    $requiredKind,
                    $actualKinds,
                    sprintf('%s lost required %s execution evidence.', $workItemId, $requiredKind),
                );
            }
        }

        self::assertSame($ledgerWorkItemIds, $workItemIds);
        $evidenceIds = array_keys($evidence);
        $referencedIds = array_keys($referenced);
        sort($evidenceIds);
        sort($referencedIds);
        self::assertSame($evidenceIds, $referencedIds, 'Phase 6 qualification contains orphaned evidence.');
    }

    /**
     * Proves each qualification marker remains in an executable source and an explicit CI lane.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed qualification artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testQualificationSourcesRemainOwnedByRealCiLanes(): void
    {
        $manifest = $this->manifest();
        $expectedOwners = [
            'ci-browser-bootstrap' => [
                'path' => '.github/workflows/ci.yml',
                'marker' => 'Migrate and create the browser-test administrator',
            ],
            'ci-browser' => [
                'path' => '.github/workflows/ci.yml',
                'marker' => 'Exercise browser, accessibility and visual contracts',
            ],
            'ci-quality' => [
                'path' => '.github/workflows/ci.yml',
                'marker' => 'Unit and architecture tests',
            ],
            'ci-database' => [
                'path' => '.github/workflows/ci.yml',
                'marker' => 'Run complete test suite',
            ],
            'ci-deployment' => [
                'path' => '.github/workflows/deployment-acceptance.yml',
                'marker' => 'Start the complete production topology',
            ],
            'ci-security' => [
                'path' => '.github/workflows/security.yml',
                'marker' => 'Validate and audit dependencies',
            ],
        ];
        $owners = $this->indexById($manifest['execution_owners']);
        self::assertSame(array_keys($expectedOwners), array_keys($owners));

        foreach ($expectedOwners as $ownerId => $expectedOwner) {
            self::assertSame($expectedOwner['path'], $owners[$ownerId]['path']);
            self::assertSame($expectedOwner['marker'], $owners[$ownerId]['marker']);
            self::assertStringContainsString(
                '- name: ' . $expectedOwner['marker'],
                $this->contents($expectedOwner['path']),
                sprintf('%s is no longer an executable workflow step.', $ownerId),
            );
        }

        $allowedKinds = [
            'executable-php',
            'phpunit',
            'playwright',
            'workflow-step',
            'qualification',
            'review',
        ];
        $usedOwners = [];
        foreach ($manifest['evidence'] as $evidence) {
            self::assertContains($evidence['kind'], $allowedKinds);
            self::assertArrayHasKey($evidence['owner'], $owners);
            $usedOwners[$evidence['owner']] = true;
            $contents = $this->contents($evidence['path']);
            $needle = match ($evidence['kind']) {
                'phpunit' => 'public function ' . $evidence['marker'] . '(',
                'playwright' => "test('" . $evidence['marker'] . "'",
                'workflow-step' => '- name: ' . $evidence['marker'],
                default => $evidence['marker'],
            };
            self::assertStringContainsString(
                $needle,
                $contents,
                sprintf('%s lost its executable marker.', $evidence['id']),
            );
            self::assertNotEmpty($evidence['must_contain']);
            foreach ($evidence['must_contain'] as $requiredToken) {
                self::assertIsString($requiredToken);
                self::assertNotSame('', $requiredToken);
                self::assertStringContainsString(
                    $requiredToken,
                    $contents,
                    sprintf('%s lost required execution token %s.', $evidence['id'], $requiredToken),
                );
            }
        }

        self::assertSame(array_keys($owners), array_keys($usedOwners));
    }

    /**
     * Proves the full diagnostic browser inventory covers every declared core navigation destination.
     *
     * Extension destinations keep their owner-specific conformance tests and are intentionally excluded
     * from the core landing-route manifest.
     *
     * @return  void
     *
     * @throws  JsonException  When the canonical surface inventory is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testFullDiagnosticsCoverEveryCoreNavigationDestination(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = json_decode(
            $this->contents('docs/interface-standard/programme/surface-inventory.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expectedPaths = [];
        foreach ($inventory['navigation_catalog'] as $navigation) {
            if (!str_starts_with($navigation['surface_id'], 'core.')) {
                continue;
            }
            self::assertContains($navigation['area'], ['administrator', 'portal']);
            self::assertSame('declared', $navigation['runtime_surface_binding']);
            $expectedPaths[] = $navigation['path'];
        }

        $matches = [];
        self::assertSame(
            1,
            preg_match_all(
                "/^[ ]{4}path: '([^']+)',$/m",
                $this->contents('tests/Browser/support/interface-surface-manifest.ts'),
                $matches,
            ) > 0 ? 1 : 0,
        );
        $diagnosticPaths = $matches[1];
        sort($expectedPaths);
        sort($diagnosticPaths);
        self::assertSame(
            $expectedPaths,
            $diagnosticPaths,
            'The full browser diagnostic manifest drifted from declared core navigation.',
        );
    }

    /**
     * Keeps final acceptance review-bound, source-owned and separate from GitHub confirmation.
     *
     * @return  void
     *
     * @throws  JsonException  When the canonical programme ledger is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testFinalAcceptanceContractPrecedesGitHubConfirmation(): void
    {
        $phaseSix = $this->indexById($this->phaseWorkItems(6));
        self::assertArrayHasKey('P6-007', $phaseSix);
        self::assertSame(
            [
                'All gates are complete',
                'No P0/P1 or unwaived P2 remains',
                'Evidence index and residual P3 ownership are explicit',
                'GitHub is final confirmation rather than the test loop',
            ],
            $phaseSix['P6-007']['acceptance'],
        );
        self::assertSame(['qualification', 'review'], $phaseSix['P6-007']['evidence_required']);

        $gates = $this->indexById($this->ledger()['gates']);
        self::assertArrayHasKey('GATE-F', $gates);
        self::assertSame(
            ['qualification', 'browser', 'security', 'review'],
            $gates['GATE-F']['required_evidence_types'],
        );

        self::assertStringContainsString(
            'GitHub is the final confirmation, not the development iteration loop.',
            $this->contents('docs/interface-standard/conformance.md'),
        );
        self::assertStringContainsString(
            'P2/P3 waiver records the finding, accountable role',
            $this->contents('docs/interface-standard/programme/governance.md'),
        );
        $continuation = $this->contents('docs/interface-standard/programme/continuation-protocol.md');
        self::assertStringContainsString(
            'work-item IDs completed, in review, blocked, and next ready',
            $continuation,
        );
        self::assertStringContainsString('open findings and severities', $continuation);

        /** @var array<string, mixed> $reportTemplate */
        $reportTemplate = json_decode(
            $this->contents('docs/interface-standard/programme/verification-report-template.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $checks = $this->indexById($reportTemplate['check_matrix']);
        self::assertArrayHasKey('HUMAN-REVIEW', $checks);
        self::assertSame('review', $checks['HUMAN-REVIEW']['category']);
        self::assertSame('required', $checks['HUMAN-REVIEW']['applicability']);
        self::assertSame('not_run', $checks['HUMAN-REVIEW']['status']);
        self::assertSame(
            'Accountable product, accessibility, security, KIS, and release roles review applicable evidence.',
            $checks['HUMAN-REVIEW']['requirement'],
        );
        self::assertSame('hold', $reportTemplate['signoff']['merge_recommendation']);
        self::assertSame('pending', $reportTemplate['signoff']['decision']);
        self::assertSame([], $reportTemplate['signoff']['reviewer_roles']);
    }

    /**
     * Decode the committed source-bound Phase 6 qualification artifact.
     *
     * @return  array<string, mixed>  Joined journeys, evidence sources and their CI execution owners.
     *
     * @throws  JsonException  When the committed artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    private function manifest(): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(
            $this->contents('tests/Fixtures/InterfaceStandard/phase-six-journey-qualification.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $manifest;
    }

    /**
     * Decode the canonical programme ledger used to bound Phase 6 qualification.
     *
     * @return  array<string, mixed>  Current gates, phases, work items and evidence governance.
     *
     * @throws  JsonException  When the canonical programme ledger is not valid JSON.
     *
     * @since   2.0.0
     */
    private function ledger(): array
    {
        /** @var array<string, mixed> $ledger */
        $ledger = json_decode(
            $this->contents('docs/interface-standard/programme/phase-ledger.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $ledger;
    }

    /**
     * Return the canonical ordered work-item records for one programme phase.
     *
     * @param   int  $phaseNumber  Exact phase number to resolve.
     *
     * @return  list<array<string, mixed>>  Canonical work items in ledger order.
     *
     * @throws  JsonException  When the canonical programme ledger is not valid JSON.
     *
     * @since   2.0.0
     */
    private function phaseWorkItems(int $phaseNumber): array
    {
        foreach ($this->ledger()['phases'] as $phase) {
            if (!is_array($phase) || ($phase['number'] ?? null) !== $phaseNumber) {
                continue;
            }
            self::assertIsArray($phase['work_items']);
            self::assertTrue(array_is_list($phase['work_items']));
            foreach ($phase['work_items'] as $workItem) {
                self::assertIsArray($workItem);
            }

            /** @var list<array<string, mixed>> $workItems */
            $workItems = $phase['work_items'];

            return $workItems;
        }

        self::fail(sprintf('The canonical programme ledger has no Phase %d.', $phaseNumber));
    }

    /**
     * Index a qualification record list by its stable identifier while rejecting duplicates.
     *
     * @param   list<array<string, mixed>>  $records  Qualification records carrying stable string identifiers.
     *
     * @return  array<string, array<string, mixed>>  Records keyed by their unique identifiers.
     *
     * @since   2.0.0
     */
    private function indexById(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            self::assertArrayHasKey('id', $record);
            self::assertIsString($record['id']);
            self::assertNotSame('', $record['id']);
            self::assertArrayNotHasKey($record['id'], $indexed, sprintf('Duplicate record %s.', $record['id']));
            $indexed[$record['id']] = $record;
        }

        return $indexed;
    }

    /**
     * Read a repository-owned source while refusing absolute, parent-relative and escaped paths.
     *
     * @param   string  $path  Repository-relative qualification source path.
     *
     * @return  string  Complete source contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        self::assertNotSame('', $path);
        self::assertFalse(str_starts_with($path, '/'));
        self::assertFalse(str_contains('/' . $path . '/', '/../'));
        $absolute = realpath($this->root . '/' . $path);
        self::assertIsString($absolute, sprintf('Unable to resolve %s.', $path));
        self::assertStringStartsWith($this->root . '/', $absolute);
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
