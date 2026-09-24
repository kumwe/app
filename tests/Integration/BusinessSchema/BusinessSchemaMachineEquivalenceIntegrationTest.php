<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSchema;

use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRecorder;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\BusinessSchema\Delivery\Administrator\RecordBusinessSchemaRecoveryEvidenceHandler;
use Kumwe\App\BusinessSchema\Delivery\Api\BusinessSchemaApiHandler;
use Kumwe\App\Delivery\Console\Command\BusinessSchemaEvidenceCommand;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves an agent composes a purge plan and files its recovery evidence exactly as the schema screen does.
 *
 * REST composes the purge plan behind the current-password re-proof, and REST, the console and the screen's own
 * handler each file a restore drill through `BusinessSchemaRecoveryEvidenceRecorder`, so each stored evidence
 * document is bound to its plan's source schema, credited to the caller, stamped with the live environment and
 * citable by an approval. REST then approves the destructive plan against the evidence the screen filed. A wrong
 * password, an unconfirmed proof and a credential without `business.schema.recover` are refused on both surfaces,
 * and nothing is filed for them. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSchemaApiHandler::class)]
#[CoversClass(RecordBusinessSchemaRecoveryEvidenceHandler::class)]
#[CoversClass(BusinessSchemaEvidenceCommand::class)]
#[CoversClass(BusinessSchemaRecoveryEvidenceRecorder::class)]
#[CoversClass(HttpMutationPreauthorizer::class)]
final class BusinessSchemaMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities of a credential that may run the whole destructive lifecycle short of execution.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array OPERATOR = [
        'business.schema.read',
        'business.schema.destructive',
        'business.schema.recover',
        'business.schema.approve',
    ];

    /**
     * The four clean-target proofs.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PROOFS = BusinessSchemaRecoveryEvidenceRecorder::PROOFS;

    /**
     * Harness of the running test.
     *
     * @var    ?MachineSurfaceHarness
     * @since  2.0.0
     */
    private ?MachineSurfaceHarness $harness = null;

    /**
     * Revoke every token the running test issued.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->harness?->cleanup();
        $this->harness = null;
    }

    /**
     * Both surfaces file the same bound evidence, and REST plans and approves the purge behind the password.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceFilesRecoveryEvidenceLikeTheSchemaScreen(): void
    {
        [$container, $harness] = $this->boot();
        $environment = $container->get(BusinessSchemaEnvironment::class);
        self::assertInstanceOf(BusinessSchemaEnvironment::class, $environment);
        $token = $harness->token('rest', self::OPERATOR);
        $plan = $this->purgePlan($container, $harness, $token);
        $traces = [];
        foreach (['rest', 'cli'] as $surface) {
            $surfaceToken = $surface === 'rest' ? $token : $harness->token('cli', self::OPERATOR);
            $filed = $this->file(
                $harness,
                $surface,
                $surfaceToken,
                $plan['id'],
                TestKernelFactory::ADMINISTRATOR_PASSWORD,
            );
            self::assertTrue($filed['ok'], $surface . ' could not file: ' . json_encode($filed));
            $evidence = $filed['value'];
            $traces[$surface] = [
                'keys' => array_keys($evidence),
                'source' => $evidence['source_schema_checksum'] === $plan['from_schema_checksum'],
                'verified_by' => $evidence['verified_by'],
                'restore_tested' => $evidence['restore_tested'],
                'environment' => [$evidence['database_driver'], $evidence['application_release']],
                'details' => array_keys($evidence['details']),
                'drill' => $evidence['drill_reference'] === 'drill-' . $surface,
            ];
            $evidenceIds[$surface] = $evidence['id'];
        }

        $screen = $container->get(RecordBusinessSchemaRecoveryEvidenceHandler::class);
        self::assertInstanceOf(RecordBusinessSchemaRecoveryEvidenceHandler::class, $screen);
        $filedOnScreen = $screen->handle((new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/business-schema-plans/recovery-evidence')
            ->withAttribute(ExecutionContextAttribute::NAME, TestKernelFactory::administratorContext($container))
            ->withParsedBody([
                'plan_id' => $plan['id'],
                ...array_fill_keys(self::PROOFS, '1'),
                'backup_manifest_checksum' => hash('sha256', 'schema-parity-backup-screen'),
                'backup_created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'verified_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'drill_reference' => 'drill-screen',
                'client_version' => 'schema-parity-client',
                'restore_target_reference' => 'clean-target-screen',
                'current_password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
            ]));
        parse_str((string) parse_url($filedOnScreen->getHeaderLine('Location'), PHP_URL_QUERY), $screenQuery);
        $evidenceIds['screen'] = $screenQuery['evidence'] ?? null;

        self::assertSame(303, $filedOnScreen->getStatusCode());
        self::assertSame('evidence-recorded', $screenQuery['notice'] ?? null);
        self::assertIsString($evidenceIds['screen']);
        self::assertSame($traces['rest'], $traces['cli']);
        self::assertTrue($traces['rest']['source']);
        self::assertTrue($traces['rest']['restore_tested']);
        self::assertSame(
            TestKernelFactory::administratorContext($container)->actorId(),
            $traces['rest']['verified_by'],
        );
        self::assertSame(
            [$environment->databaseDriver(), $environment->applicationRelease()],
            $traces['rest']['environment'],
        );

        $approved = $harness->rest($token, 'POST', '/api/v1/business-schema-plans/' . $plan['id'] . '/approve', [
            'expected_checksum' => $plan['checksum'],
            'confirmation' => $plan['checksum'],
            'recovery_evidence_id' => $evidenceIds['screen'],
            'current_password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
        ], ['Idempotency-Key' => 'schema-parity-approve-' . bin2hex(random_bytes(6))]);

        self::assertSame(200, $approved['status'], $approved['raw']);
        self::assertIsArray($approved['body']);
        self::assertSame('approved', $approved['body']['status']);
    }

    /**
     * A wrong password, an unconfirmed proof and a missing grant are refused on both surfaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsMatchOnBothSurfaces(): void
    {
        [$container, $harness] = $this->boot();
        $operator = [
            'rest' => $harness->token('rest', self::OPERATOR),
            'cli' => $harness->token('cli', self::OPERATOR),
        ];
        $reader = [
            'rest' => $harness->token('rest', ['business.schema.read']),
            'cli' => $harness->token('cli', ['business.schema.read']),
        ];
        $plan = $this->purgePlan($container, $harness, $operator['rest']);
        $wrongPurge = $harness->rest($operator['rest'], 'POST', '/api/v1/business-schema-plans/purge', [
            'definition_id' => $plan['definition_id'],
            'current_password' => 'not the administrator password',
        ], ['Idempotency-Key' => 'schema-parity-purge-wrong-' . bin2hex(random_bytes(6))]);
        $refusals = [];
        foreach (['rest', 'cli'] as $surface) {
            $refusals[$surface] = [
                'password' => $this->file(
                    $harness,
                    $surface,
                    $operator[$surface],
                    $plan['id'],
                    'wrong password',
                )['refusal'],
                'proof' => $this->file(
                    $harness,
                    $surface,
                    $operator[$surface],
                    $plan['id'],
                    TestKernelFactory::ADMINISTRATOR_PASSWORD,
                    array_slice(self::PROOFS, 1),
                )['refusal'],
                'grant' => $this->file(
                    $harness,
                    $surface,
                    $reader[$surface],
                    $plan['id'],
                    TestKernelFactory::ADMINISTRATOR_PASSWORD,
                )['refusal'],
            ];
        }

        $path = '/api/v1/business-schema-plans/' . $plan['id'] . '/recovery-evidence';
        $valid = [
            'proofs' => self::PROOFS,
            'backup_manifest_checksum' => hash('sha256', 'schema-parity-malformed'),
            'backup_created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'verified_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'drill_reference' => 'drill-malformed',
            'client_version' => 'schema-parity-client',
            'restore_target_reference' => 'clean-target-malformed',
            'current_password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
        ];
        $malformed = [];
        foreach (
            [
                'extra member' => [...$valid, 'restore_tested' => true],
                'proof map' => [...$valid, 'proofs' => ['clean_target_restore' => true]],
                'numeric password' => [...$valid, 'current_password' => 7],
                'unreadable date' => [...$valid, 'verified_at' => 'yesterday-ish'],
            ] as $label => $body
        ) {
            $malformed[$label] = $harness->rest($operator['rest'], 'POST', $path, $body, [
                'Idempotency-Key' => 'schema-parity-malformed-' . bin2hex(random_bytes(6)),
            ])['status'];
        }
        $unsupported = $harness->cli(BusinessSchemaEvidenceCommand::class, $operator['cli'], ['withdraw']);
        $undated = $harness->cli(BusinessSchemaEvidenceCommand::class, $operator['cli'], [
            'record',
            '--plan=' . $plan['id'],
            '--proofs=' . implode(',', self::PROOFS),
            '--backup-manifest-checksum=' . hash('sha256', 'schema-parity-undated'),
            '--backup-created-at=not-a-date',
            '--verified-at=not-a-date',
            '--drill-reference=drill-undated',
            '--client-version=schema-parity-client',
            '--restore-target-reference=clean-target-undated',
            '--password-file=' . $harness->protectedFile(TestKernelFactory::ADMINISTRATOR_PASSWORD),
        ]);

        self::assertSame(
            ['extra member' => 422, 'proof map' => 422, 'numeric password' => 422, 'unreadable date' => 422],
            $malformed,
        );
        self::assertNotSame(0, $unsupported['status']);
        self::assertSame([1, 'The --backup-created-at option is invalid.'], [$undated['status'], $undated['stderr']]);
        self::assertSame(403, $wrongPurge['status'], $wrongPurge['raw']);
        self::assertSame(['password' => 403, 'proof' => 422, 'grant' => 403], $refusals['rest']);
        self::assertSame(['password' => 1, 'proof' => 1, 'grant' => 1], $refusals['cli']);
    }

    /**
     * Boot the kernel and a harness bound to it.
     *
     * @return  array{Container, MachineSurfaceHarness}  Kernel and harness.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'schema-parity');

        return [$container, $this->harness];
    }

    /**
     * Install a fresh neutral definition and compose its purge plan over REST behind the password re-proof.
     *
     * @param   Container              $container  Kernel.
     * @param   MachineSurfaceHarness  $harness    Harness.
     * @param   string                 $token      REST token holding `business.schema.destructive`.
     *
     * @return  array<string, mixed>  The composed plan document plus its `definition_id`.
     *
     * @since   2.0.0
     */
    private function purgePlan(Container $container, MachineSurfaceHarness $harness, string $token): array
    {
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definitionId = Uuid::uuid7()->toString();
        NeutralBusinessFixture::install(
            $container,
            TestKernelFactory::administratorContext($container),
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        $response = $harness->rest($token, 'POST', '/api/v1/business-schema-plans/purge', [
            'definition_id' => $definitionId,
            'current_password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
        ], ['Idempotency-Key' => 'schema-parity-purge-' . bin2hex(random_bytes(6))]);
        self::assertSame(201, $response['status'], $response['raw']);
        self::assertIsArray($response['body']);
        self::assertNotNull($response['body']['from_schema_checksum'] ?? null);
        // An approval accepts only a drill verified after the plan was persisted, at second precision.
        sleep(1);

        return [...$response['body'], 'definition_id' => $definitionId];
    }

    /**
     * File one drill on one surface.
     *
     * @param   MachineSurfaceHarness  $harness   Harness.
     * @param   string                 $surface   `rest` or `cli`.
     * @param   string                 $token     Surface token.
     * @param   string                 $planId    Plan the drill restored the source schema of.
     * @param   string                 $password  Password re-entered for the re-proof.
     * @param   list<string>           $proofs    Confirmed clean-target proofs.
     *
     * @return  array{ok: bool, value: mixed, refusal: ?int}  Evidence document, or the surface's refusal.
     *
     * @since   2.0.0
     */
    private function file(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $planId,
        string $password,
        array $proofs = self::PROOFS,
    ): array {
        $drill = [
            'backup_manifest_checksum' => hash('sha256', 'schema-parity-backup-' . $surface),
            'backup_created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'verified_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'drill_reference' => 'drill-' . $surface,
            'client_version' => 'schema-parity-client',
            'restore_target_reference' => 'clean-target-' . $surface,
        ];
        if ($surface === 'rest') {
            $path = '/api/v1/business-schema-plans/' . $planId . '/recovery-evidence';
            $response = $harness->rest($token, 'POST', $path, [
                ...$drill,
                'proofs' => $proofs,
                'current_password' => $password,
            ], ['Idempotency-Key' => 'schema-parity-evidence-' . bin2hex(random_bytes(6))]);

            return $response['status'] === 201
                ? ['ok' => true, 'value' => $response['body'], 'refusal' => null]
                : ['ok' => false, 'value' => $response['body'], 'refusal' => $response['status']];
        }
        $arguments = ['record', '--plan=' . $planId, '--proofs=' . implode(',', $proofs)];
        foreach ($drill as $name => $value) {
            $arguments[] = '--' . str_replace('_', '-', $name) . '=' . $value;
        }
        $arguments[] = '--password-file=' . $harness->protectedFile($password);
        $run = $harness->cli(BusinessSchemaEvidenceCommand::class, $token, $arguments);

        return $run['status'] === 0
            ? ['ok' => true, 'value' => $run['stdout'], 'refusal' => null]
            : ['ok' => false, 'value' => $run['stderr'], 'refusal' => $run['status']];
    }
}
