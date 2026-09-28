<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use BrowserMachineParityVerifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves every administrator and portal browser operation has a machine equivalent or a reasoned exemption.
 *
 * `docs/machine-contract/browser-machine-parity.json` records each browser route, the application operations it
 * performs, and for each operation either the REST operation ids, CLI actions and MCP tools that call the same
 * application service, or a browser-only reason. The verifier behind `composer machine:parity` checks the record
 * against the literal route table, the current REST generation, the live CLI generation and the live MCP
 * catalogue. This suite runs that check, proves each refusal actually fires on a mutated record, and proves
 * the check is wired into `composer qa`, the quality contract and CI. RestMachineContractParityTest compares
 * the literal browser route table and recorded handlers with the booted router in the database-backed lane.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class BrowserMachineParityGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Load the dependency-light verifier once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2) . '/tools/verify-browser-machine-parity.php';
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
     * The committed record has no gap, no stale entry and names only existing machine operations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRecordMatchesTheRouteTableAndTheLiveMachineContracts(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $violations = $verifier->violations();

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
        $summary = BrowserMachineParityVerifier::summary($verifier->record());
        self::assertSame(0, $summary['classifications']['gap']);
        self::assertGreaterThan(0, $summary['classifications']['equivalent']);
    }

    /**
     * A browser route the record omits, and a recorded route the table no longer registers, are both refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAnUnrecordedRouteAndAStaleEntry(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $document = $verifier->record();
        $surfaces = $verifier->surfaces();
        self::assertIsArray($document['routes']);
        $removed = array_shift($document['routes']);
        self::assertIsArray($removed);
        $document['routes'][] = [...$removed, 'name' => 'administrator.retired-screen'];

        $violations = implode(PHP_EOL, $verifier->violationsFor($document, $surfaces));

        self::assertStringContainsString('Browser route ' . $removed['name'], $violations);
        self::assertStringContainsString('has no parity entry', $violations);
        self::assertStringContainsString('Parity route administrator.retired-screen is stale', $violations);
    }

    /**
     * A reference to a REST operation, CLI action or MCP tool the live contracts do not declare is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAMachineOperationThatDoesNotExist(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $document = $verifier->record();
        [$route, $operation] = self::firstEquivalent($document);
        $document['routes'][$route]['operations'][$operation]['rest'] = ['missingParityOperation'];
        $document['routes'][$route]['operations'][$operation]['cli'] = ['access missing-parity-action'];
        $document['routes'][$route]['operations'][$operation]['mcp'] = ['kumwe_missing_parity_tool'];
        $document['routes'][$route]['operations'][$operation]['service'] = 'Kumwe\\App\\Missing\\Service::run';

        $violations = implode(PHP_EOL, $verifier->violationsFor($document, $verifier->surfaces()));

        self::assertStringContainsString('REST operation "missingParityOperation"', $violations);
        self::assertStringContainsString('CLI operation "access missing-parity-action"', $violations);
        self::assertStringContainsString('MCP operation "kumwe_missing_parity_tool"', $violations);
        self::assertStringContainsString('Kumwe\\App\\Missing\\Service::run', $violations);
    }

    /**
     * A gap, an unreasoned surface exemption and an unreasoned browser-only operation are each refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAGapAndEveryUnreasonedExemption(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $document = $verifier->record();
        [$route, $operation] = self::firstEquivalent($document);
        $gap = $document;
        $gap['routes'][$route]['operations'][$operation] = [
            'operation' => 'retired',
            'classification' => 'gap',
            'note' => 'no machine operation yet',
        ];
        $exempted = $document;
        $exempted['routes'][$route]['operations'][$operation]['mcp'] = [];
        $exempted['routes'][$route]['operations'][$operation]['exemptions'] = [
            'mcp' => ['reason' => 'convenience', 'note' => 'not a recorded reason'],
        ];
        $silent = $document;
        $silent['routes'][$route]['operations'][$operation]['cli'] = [];
        $browserOnly = $document;
        $browserOnly['routes'][$route]['operations'][$operation] = [
            'operation' => 'retired',
            'classification' => 'browser-only',
            'reason' => 'preference-of-the-author',
            'note' => 'not a recorded reason',
        ];
        $surfaces = $verifier->surfaces();

        self::assertStringContainsString(
            'has neither a machine equivalent nor a reasoned exemption',
            implode(PHP_EOL, $verifier->violationsFor($gap, $surfaces)),
        );
        self::assertStringContainsString(
            'exempts MCP without a reason from the surface_exemption_reasons vocabulary',
            implode(PHP_EOL, $verifier->violationsFor($exempted, $surfaces)),
        );
        self::assertStringContainsString(
            'has no CLI operation and no reasoned CLI exemption',
            implode(PHP_EOL, $verifier->violationsFor($silent, $surfaces)),
        );
        self::assertStringContainsString(
            'has no reason from the browser_only_reasons vocabulary',
            implode(PHP_EOL, $verifier->violationsFor($browserOnly, $surfaces)),
        );
    }

    /**
     * Each Studio host operation is classified individually, and a newly served one without an entry is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryServedStudioHostOperationIsClassifiedIndividually(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $surfaces = $verifier->surfaces();
        $recorded = [];
        foreach ($verifier->record()['routes'] as $route) {
            self::assertIsArray($route);
            if ($route['name'] === BrowserMachineParityVerifier::STUDIO_HOST_ROUTE) {
                $recorded = array_column($route['operations'], 'operation');
            }
        }
        sort($recorded, SORT_STRING);
        $served = $surfaces['studio'];
        sort($served, SORT_STRING);
        self::assertSame($served, $recorded);
        $surfaces['studio'][] = 'studio.operation/unrecorded.operation';

        self::assertStringContainsString(
            'Studio host operation studio.operation/unrecorded.operation is served but has no parity entry',
            implode(PHP_EOL, $verifier->violationsFor($verifier->record(), $surfaces)),
        );
    }

    /**
     * The machine operations that authorize with less assurance than the browser are exactly the pinned set.
     *
     * Each is a released v1 machine contract the browser now gates on a step-up or password re-proof; the set is
     * awaiting a maintainer decision, and this pin makes any addition or removal a reviewed change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssuranceGapsArePinned(): void
    {
        $summary = BrowserMachineParityVerifier::summary((new BrowserMachineParityVerifier($this->root))->record());
        $access = 'administrator.access-control.update ';

        self::assertSame([
            $access . 'user.create',
            $access . 'user.update',
            $access . 'role.create',
            $access . 'role.assign',
            $access . 'role.revoke',
            $access . 'grant.create',
            $access . 'grant.revoke',
            $access . 'grant.synchronize',
            $access . 'token.create',
            $access . 'token.revoke',
            $access . 'token.rotate',
            $access . 'token.emergency_revoke',
            'administrator.business-schema-plans.approve approve',
            'administrator.business-schema-plans.purge purge-plan',
        ], $summary['assurance_gaps']);
    }

    /**
     * The account recoveries the browser gates on a step-up proof exist on no machine surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpGatedAccountRecoveryIsBrowserOnlyOnEverySurface(): void
    {
        $verifier = new BrowserMachineParityVerifier($this->root);
        $surfaces = $verifier->surfaces();
        $classified = [];
        foreach ($verifier->record()['routes'] as $route) {
            self::assertIsArray($route);
            foreach ($route['operations'] as $operation) {
                if (($operation['reason'] ?? null) === 'human-step-up') {
                    $classified[] = $operation['operation'];
                }
            }
        }

        self::assertSame(['user.password.reset', 'user.step_up.revoke', 'user.sessions.terminate'], $classified);
        foreach (['resetUserPassword', 'revokeUserStepUpCredentials', 'terminateUserSessions'] as $operation) {
            self::assertNotContains($operation, $surfaces['rest']);
        }
        foreach (
            [
                'kumwe_user_step_up_revoke',
                'kumwe_user_sessions_terminate',
                'kumwe_user_role_revoke',
                'kumwe_role_grant_revoke',
            ] as $tool
        ) {
            self::assertNotContains($tool, $surfaces['mcp']);
        }
    }

    /**
     * The gate runs in `composer qa`, is declared in the quality contract and runs in the CI quality job.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateIsWiredIntoTheQualityLane(): void
    {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(
            'php tools/verify-browser-machine-parity.php',
            $composer['scripts']['machine:parity'] ?? null,
        );
        self::assertContains('@machine:parity', $composer['scripts']['qa'] ?? []);
        $contract = json_decode((string) file_get_contents($this->root . '/docs/quality/contract.json'), true);
        self::assertIsArray($contract);
        $check = null;
        foreach ($contract['checks'] as $candidate) {
            if (($candidate['composer_script'] ?? null) === 'machine:parity') {
                $check = $candidate;
            }
        }
        self::assertIsArray($check);
        self::assertTrue($check['in_qa']);
        self::assertStringContainsString(
            'composer machine:parity',
            (string) file_get_contents($this->root . '/.github/workflows/ci.yml'),
        );
    }

    /**
     * Locate the first equivalent operation of the record.
     *
     * @param   array<string, mixed>  $document  Decoded parity record.
     *
     * @return  array{int, int}  Route and operation offsets.
     *
     * @since   2.0.0
     */
    private static function firstEquivalent(array $document): array
    {
        self::assertIsArray($document['routes']);
        foreach ($document['routes'] as $routeIndex => $route) {
            self::assertIsArray($route);
            foreach ($route['operations'] as $operationIndex => $operation) {
                if (
                    ($operation['classification'] ?? null) === 'equivalent'
                    && ($operation['exemptions'] ?? []) === []
                ) {
                    return [$routeIndex, $operationIndex];
                }
            }
        }
        self::fail('The record has no unexempted equivalent operation.');
    }
}
