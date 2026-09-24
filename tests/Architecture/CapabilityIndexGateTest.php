<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Tests\Unit\Governance\GovernanceFixture;
use Kumwe\App\Tools\Governance\CapabilityIndexBuilder;
use Kumwe\App\Tools\Governance\CapabilityIndexWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the capability index gate holds for this repository and is registered in every lane that must run it.
 *
 * The committed `docs/architecture/capability-index.md` matches what the installed Kumwe packages generate, the
 * generator is deterministic, a stale digest is refused, every installed package is indexed from its Version 2
 * manifests and ledger record now that `kumwe/extension-sdk` has left the legacy registry, and the check is wired
 * into `composer qa`, the quality contract, both CI steps and the coverage contract.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CapabilityIndexGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/Governance/bootstrap.php';
        require_once dirname(__DIR__) . '/Unit/Governance/GovernanceFixture.php';
    }

    /**
     * Resolve the repository root.
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
     * The committed index is current for the installed packages and its digest is the one the tool computes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedIndexIsCurrent(): void
    {
        $check = GovernanceFixture::run(['--check']);

        self::assertSame(0, $check['status'], $check['output']);
        self::assertStringContainsString('Capability index verified (30 packages; digest sha256:', $check['output']);

        $digest = GovernanceFixture::run(['--digest']);
        self::assertSame(0, $digest['status'], $digest['output']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest['output']);
        self::assertStringContainsString('digest sha256:' . $digest['output'], $check['output']);
        $markdown = file_get_contents($this->root . '/docs/architecture/capability-index.md');
        self::assertIsString($markdown);
        self::assertSame($digest['output'], CapabilityIndexWriter::embeddedDigest($markdown));
        self::assertSame($digest['output'], GovernanceFixture::run(['--digest'])['output'], 'The digest is stable.');
    }

    /**
     * Writing twice produces identical bytes, and the fixture root passes its own check afterwards.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritingTwiceProducesIdenticalBytes(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $first = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $first['status'], $first['output']);
            self::assertStringContainsString('Capability index written (2 packages; digest sha256:', $first['output']);
            $json = GovernanceFixture::read($root, CapabilityIndexWriter::JSON_PATH);
            $digest = GovernanceFixture::read($root, CapabilityIndexWriter::DIGEST_PATH);
            $markdown = GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH);

            $second = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $second['status'], $second['output']);
            self::assertSame($json, GovernanceFixture::read($root, CapabilityIndexWriter::JSON_PATH));
            self::assertSame($digest, GovernanceFixture::read($root, CapabilityIndexWriter::DIGEST_PATH));
            self::assertSame($markdown, GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH));
            self::assertSame(hash('sha256', $json) . "  v1.json\n", $digest);
            self::assertSame(
                file_get_contents(GovernanceFixture::cleanRoot() . '/docs/architecture/capability-index.md'),
                $markdown,
                'The committed fixture markdown is what the tool generates.',
            );
            self::assertStringNotContainsString($root, $json, 'No absolute path leaks into the generated index.');

            $check = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(0, $check['status'], $check['output']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A stale embedded digest and a hand-edited document are refused, naming the file and the regenerate command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleDigestIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $markdown = GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH);
            $digest = CapabilityIndexWriter::embeddedDigest($markdown);
            self::assertNotNull($digest);
            GovernanceFixture::replace($root, CapabilityIndexWriter::MARKDOWN_PATH, $digest, strrev($digest));

            $stale = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $stale['status'], $stale['output']);
            self::assertStringContainsString(
                'Capability index: docs/architecture/capability-index.md embeds the stale digest',
                $stale['output'],
            );
            self::assertStringContainsString('composer kumwe:capability-index', $stale['output']);

            GovernanceFixture::write($root, CapabilityIndexWriter::MARKDOWN_PATH, $markdown . "\nHand edit.\n");
            $edited = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $edited['status'], $edited['output']);
            self::assertStringContainsString('differs from the regenerated index', $edited['output']);

            GovernanceFixture::replace($root, 'composer.lock', '"version": "v0.9.0"', '"version": "v0.9.1"');
            $unapproved = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $unapproved['status'], $unapproved['output']);
            self::assertStringContainsString(
                'Capability index: docs/architecture/governance/legacy-packages.json',
                $unapproved['output'],
            );
            self::assertStringContainsString('approved at v0.9.0 but v0.9.1 is locked', $unapproved['output']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * The tool refuses unknown arguments and an absent mode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheToolRefusesUnknownArguments(): void
    {
        $unknown = GovernanceFixture::run(['--check', '--verbose']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Capability index: Unknown argument --verbose', $unknown['output']);

        $none = GovernanceFixture::run([]);
        self::assertSame(1, $none['status']);
        self::assertStringContainsString('No mode given', $none['output']);

        $both = GovernanceFixture::run(['--check', '--write']);
        self::assertSame(1, $both['status']);
        self::assertStringContainsString('exactly one mode', $both['output']);
    }

    /**
     * No legacy-unmanifested entry remains: the thirty installed packages are Version 2 adoptions with release
     * metadata, the extension-sdk train binds the SDK release record and retires its three legacy App roots, the
     * transaction, localization and secret-envelope adoptions preserve the access-context and sequence handoffs
     * and removed-symbol mappings, and the navigation adoption retires its App domain root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledPackagesAreLegacyEntriesOrVersionTwoAdoptions(): void
    {
        $document = (new CapabilityIndexBuilder($this->root))->build();
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];

        self::assertSame(
            [
                'kumwe/access-context',
                'kumwe/access-control',
                'kumwe/administrator-contract',
                'kumwe/approval',
                'kumwe/audit',
                'kumwe/automation',
                'kumwe/business-definition',
                'kumwe/business-policy',
                'kumwe/business-schema',
                'kumwe/business-surface-contract',
                'kumwe/canonical-json',
                'kumwe/computation',
                'kumwe/content-model',
                'kumwe/contribution',
                'kumwe/conversion',
                'kumwe/extension-sdk',
                'kumwe/idempotency',
                'kumwe/integration',
                'kumwe/interface-standard',
                'kumwe/localization',
                'kumwe/navigation',
                'kumwe/portal-contract',
                'kumwe/producer',
                'kumwe/record-model',
                'kumwe/record-query',
                'kumwe/record-values',
                'kumwe/reporting',
                'kumwe/secret-envelope',
                'kumwe/sequence',
                'kumwe/transaction',
            ],
            array_column($packages, 'package'),
        );
        foreach ($packages as $package) {
            self::assertSame('v2-manifested', $package['manifest_status'], (string) $package['package']);
            self::assertTrue($package['release_gate_eligible'], (string) $package['package']);
            self::assertNull($package['legacy'], (string) $package['package']);
            self::assertIsArray($package['handoff'], (string) $package['package']);
        }
        $access = $packages[0];
        self::assertSame('v2-manifested', $access['manifest_status']);
        self::assertTrue($access['release_gate_eligible']);
        self::assertNull($access['legacy']);
        self::assertIsArray($access['handoff']);
        self::assertSame('KUMWE-MIG-2026-004', $access['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-004', $access['handoff']['change_set']);
        self::assertSame('vendor/kumwe/access-context/MIGRATION-HANDOFF.md', $access['handoff']['path']);
        self::assertContains('Kumwe\\Context\\Value\\ExecutionContext', $access['public_symbols']);
        $canonical = $packages[10];
        self::assertSame('v2-manifested', $canonical['manifest_status']);
        self::assertTrue($canonical['release_gate_eligible']);
        self::assertNull($canonical['legacy']);
        self::assertIsArray($canonical['handoff']);
        self::assertSame('KUMWE-MIG-2026-007', $canonical['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-007', $canonical['handoff']['change_set']);
        self::assertSame('vendor/kumwe/canonical-json/MIGRATION-HANDOFF.md', $canonical['handoff']['path']);
        self::assertContains('Kumwe\\CanonicalJson\\Profile', $canonical['public_symbols']);
        $conversion = $packages[14];
        self::assertSame('v2-manifested', $conversion['manifest_status']);
        self::assertTrue($conversion['release_gate_eligible']);
        self::assertNull($conversion['legacy']);
        self::assertIsArray($conversion['handoff']);
        self::assertSame('KUMWE-MIG-2026-031', $conversion['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-031', $conversion['handoff']['change_set']);
        self::assertSame('vendor/kumwe/conversion/MIGRATION-HANDOFF.md', $conversion['handoff']['path']);
        self::assertContains('Kumwe\\Conversion\\Decimal\\ExactDecimal', $conversion['public_symbols']);
        $producer = $packages[22];
        self::assertSame('v2-manifested', $producer['manifest_status']);
        self::assertTrue($producer['release_gate_eligible']);
        self::assertNull($producer['legacy']);
        self::assertIsArray($producer['handoff']);
        self::assertSame('KUMWE-MIG-2026-032', $producer['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-032', $producer['handoff']['change_set']);
        self::assertSame('vendor/kumwe/producer/MIGRATION-HANDOFF.md', $producer['handoff']['path']);
        self::assertContains('Kumwe\\Producer\\Deployment\\StudioDeploymentEmitter', $producer['public_symbols']);
        $sources = array_column($packages, 'public_symbols_source', 'package');
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/access-context']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/canonical-json']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/conversion']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/extension-sdk']);
        $sdk = $packages[15];
        self::assertSame('kumwe/extension-sdk', $sdk['package']);
        self::assertIsArray($sdk['handoff']);
        self::assertSame('KUMWE-MIG-2026-033', $sdk['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-033', $sdk['handoff']['change_set']);
        self::assertSame('vendor/kumwe/extension-sdk/docs/release-record.md', $sdk['handoff']['path']);
        self::assertCount(96, $sdk['public_symbols']);
        self::assertContains('Kumwe\\Extension\\Manifest\\ExtensionManifest', $sdk['public_symbols']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/producer']);
        self::assertSame(
            [
                'v0.1.2',
                'v0.1.2',
                'v0.2.2',
                'v0.1.2',
                'v0.1.2',
                'v0.2.2',
                'v0.1.2',
                'v0.1.1',
                'v0.1.3',
                'v0.1.4',
                'v0.1.1',
                'v0.3.3',
                'v0.2.0',
                'v0.1.1',
                'v0.1.5',
                'v0.3.3',
                'v0.1.3',
                'v0.2.4',
                'v0.1.2',
                'v0.1.1',
                'v0.1.3',
                'v0.2.2',
                'v0.3.0',
                'v0.1.4',
                'v0.1.4',
                'v0.1.4',
                'v0.1.5',
                'v0.1.1',
                'v0.2.1',
                'v0.1.2',
            ],
            array_column($packages, 'installed_version'),
        );
        self::assertSame(
            [
                [
                    'old_namespace' => 'Kumwe\\App\\Application\\Idempotency\\',
                    'package' => 'kumwe/idempotency',
                    'migration_id' => 'KUMWE-MIG-2026-020',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Application\\Persistence\\',
                    'package' => 'kumwe/transaction',
                    'migration_id' => 'KUMWE-MIG-2026-001',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\BusinessIntegration\\Domain\\',
                    'package' => 'kumwe/integration',
                    'migration_id' => 'KUMWE-MIG-2026-027',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\BusinessRecord\\Query\\',
                    'package' => 'kumwe/extension-sdk',
                    'migration_id' => 'KUMWE-MIG-2026-033',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\BusinessSecurity\\Application\\Approval\\',
                    'package' => 'kumwe/approval',
                    'migration_id' => 'KUMWE-MIG-2026-023',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\BusinessSecurity\\Policy\\',
                    'package' => 'kumwe/business-policy',
                    'migration_id' => 'KUMWE-MIG-2026-022',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Content\\Domain\\',
                    'package' => 'kumwe/content-model',
                    'migration_id' => 'KUMWE-MIG-2026-034',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Extension\\Development\\',
                    'package' => 'kumwe/extension-sdk',
                    'migration_id' => 'KUMWE-MIG-2026-033',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Extension\\Infrastructure\\Package\\',
                    'package' => 'kumwe/extension-sdk',
                    'migration_id' => 'KUMWE-MIG-2026-033',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Localization\\Domain\\',
                    'package' => 'kumwe/localization',
                    'migration_id' => 'KUMWE-MIG-2026-005',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Navigation\\Domain\\',
                    'package' => 'kumwe/navigation',
                    'migration_id' => 'KUMWE-MIG-2026-035',
                ],
                [
                    'old_namespace' => 'Kumwe\\App\\Workflow\\Domain\\',
                    'package' => 'kumwe/content-model',
                    'migration_id' => 'KUMWE-MIG-2026-034',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessIntegration\\Domain\\',
                    'package' => 'kumwe/integration',
                    'migration_id' => 'KUMWE-MIG-2026-027',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessRecord\\Query\\',
                    'package' => 'kumwe/record-query',
                    'migration_id' => 'KUMWE-MIG-2026-039',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessReporting\\Application\\',
                    'package' => 'kumwe/reporting',
                    'migration_id' => 'KUMWE-MIG-2026-041',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessReporting\\Domain\\',
                    'package' => 'kumwe/reporting',
                    'migration_id' => 'KUMWE-MIG-2026-041',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessSecurity\\',
                    'package' => 'kumwe/business-policy',
                    'migration_id' => 'KUMWE-MIG-2026-022',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\BusinessSurface\\',
                    'package' => 'kumwe/business-surface-contract',
                    'migration_id' => 'KUMWE-MIG-2026-038',
                ],
                [
                    'old_namespace' => 'Kumwe\\Extension\\Spi\\Portal\\Contribution\\',
                    'package' => 'kumwe/portal-contract',
                    'migration_id' => 'KUMWE-MIG-2026-037',
                ],
            ],
            $document['extracted_namespaces'],
        );
        /** @var list<array{old_fqcn: string, new_fqcn: string, package: string, migration_id: string}> $removed */
        $removed = $document['removed_symbols'];
        self::assertSame(
            [
                'kumwe/access-context' => 8,
                'kumwe/access-control' => 37,
                'kumwe/automation' => 24,
                'kumwe/idempotency' => 7,
                'kumwe/transaction' => 3,
                'kumwe/audit' => 13,
                'kumwe/business-definition' => 36,
                'kumwe/computation' => 3,
                'kumwe/sequence' => 4,
                'kumwe/integration' => 33,
                'kumwe/conversion' => 23,
                'kumwe/record-model' => 6,
                'kumwe/secret-envelope' => 9,
                'kumwe/record-values' => 3,
                'kumwe/reporting' => 21,
                'kumwe/business-schema' => 22,
                'kumwe/approval' => 12,
                'kumwe/business-policy' => 14,
                'kumwe/content-model' => 30,
                'kumwe/contribution' => 3,
                'kumwe/interface-standard' => 21,
                'kumwe/localization' => 23,
                'kumwe/navigation' => 8,
                'kumwe/extension-sdk' => 1,
                'kumwe/record-query' => 27,
                'kumwe/business-surface-contract' => 18,
                'kumwe/administrator-contract' => 4,
                'kumwe/portal-contract' => 4,
            ],
            array_count_values(array_column($removed, 'package')),
        );
        $removed = array_values(array_filter(
            $removed,
            static fn (array $entry): bool => in_array(
                $entry['package'],
                ['kumwe/access-context', 'kumwe/sequence'],
                true,
            ),
        ));
        self::assertSame(
            [
                'Kumwe\\App\\Application\\Authorization\\AuthenticatedSurface',
                'Kumwe\\App\\Application\\Authorization\\AuthenticationStrength',
                'Kumwe\\App\\Application\\Authorization\\ExecutionContext',
                'Kumwe\\App\\Application\\Authorization\\MembershipContext',
                'Kumwe\\App\\Application\\Authorization\\OrganizationContext',
                'Kumwe\\App\\Application\\Authorization\\SiteContext',
                'Kumwe\\App\\Application\\Authorization\\StepUpProof',
                'Kumwe\\App\\Application\\Authorization\\WorkspaceContext',
                'Kumwe\\App\\BusinessDefinition\\Domain\\NumberSequenceFormat',
                'Kumwe\\App\\BusinessDefinition\\Domain\\NumberSequenceReset',
                'Kumwe\\App\\BusinessDefinition\\Domain\\NumberSequenceScope',
                'Kumwe\\App\\BusinessRecord\\Application\\BusinessNumberSequenceAllocator',
            ],
            array_column($removed, 'old_fqcn'),
        );
        self::assertSame(
            ['KUMWE-MIG-2026-004', 'KUMWE-MIG-2026-002'],
            array_values(array_unique(array_column($removed, 'migration_id'))),
        );
        self::assertSame(
            ['kumwe/access-context', 'kumwe/sequence'],
            array_values(array_unique(array_column($removed, 'package'))),
        );
        self::assertContains(
            [
                'old_fqcn' => 'Kumwe\\Extension\\Spi\\Application\\Automation\\IdempotencyKey',
                'new_fqcn' => 'Kumwe\\Idempotency\\IdempotencyKey',
                'package' => 'kumwe/extension-sdk',
                'migration_id' => 'KUMWE-MIG-2026-033',
            ],
            $document['removed_symbols'],
        );
    }

    /**
     * The check is a `composer qa` member after the Studio pins, a quality-contract check, two CI steps and a
     * reasoned coverage path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateIsRegisteredInEveryLane(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame(
            'php tools/generate-capability-index.php --write',
            $composer['scripts']['kumwe:capability-index'] ?? null,
        );
        self::assertSame(
            'php tools/generate-capability-index.php --check',
            $composer['scripts']['kumwe:capability-index-check'] ?? null,
        );
        /** @var list<string> $qa */
        $qa = $composer['scripts']['qa'];
        $studio = array_search('@studio:dependencies', $qa, true);
        self::assertIsInt($studio);
        self::assertSame('@kumwe:capability-index-check', $qa[$studio + 1] ?? null);

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        $checks = array_values(array_filter(
            $contract['checks'] ?? [],
            static fn (mixed $check): bool => is_array($check) && ($check['id'] ?? null) === 'capability-index',
        ));
        self::assertCount(1, $checks);
        self::assertSame('kumwe:capability-index-check', $checks[0]['composer_script'] ?? null);
        self::assertSame('platform-architecture', $checks[0]['owner'] ?? null);
        self::assertSame('docs/architecture/capability-index.md', $checks[0]['artifact'] ?? null);
        self::assertTrue($checks[0]['in_qa'] ?? false);
        self::assertSame(['local', 'ci', 'nightly', 'release'], $checks[0]['cadence'] ?? null);
        self::assertSame('quality', $checks[0]['workflows']['ci']['job'] ?? null);

        $workflow = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertSame(2, substr_count($workflow, "          composer kumwe:capability-index-check\n"));
        self::assertStringContainsString(
            'Verify the pinned Studio contract corpus and the capability index',
            $workflow,
        );
        self::assertStringContainsString(
            "          composer studio:dependencies\n          composer kumwe:capability-index-check\n",
            $workflow,
        );

        $coverage = $this->document($this->root . '/docs/quality/coverage-contract.json');
        $paths = array_column($coverage['attribution']['reasoned'] ?? [], 'path');
        self::assertContains('tests/Unit/Governance/', $paths);
    }

    /**
     * Decode one repository JSON object.
     *
     * @param   string  $path  Document path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
