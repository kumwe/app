<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\CMS\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\CMS\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\CMS\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\CMS\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\GeneratedBusinessParityOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ManageBusinessRecordsCommand::class)]
/**
 * Verifies the generated-business command's strict grammar, authorization order and stable failures.
 *
 * @since  2.0.0
 */
final class ManageBusinessRecordsCommandTest extends TestCase
{
    /**
     * Stable actor identity issued by the recording token verifier.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * Owner-protected input files awaiting cleanup.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove every protected command input created by a test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Pin the complete command action registry and each capability mapping.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCommandCoversTheCompleteBoundedRecordSurfaceWithExactCapabilities(): void
    {
        $capabilities = $this->constant('CAPABILITIES');

        self::assertSame([
            'entities', 'schema', 'view', 'list', 'get', 'create', 'update', 'archive', 'restore', 'delete',
            'action', 'request-action', 'approvals', 'approval', 'history', 'relate', 'unrelate', 'reorder',
            'report', 'export', 'operation',
        ], array_keys($capabilities));
        self::assertSame('business.record.browse', $capabilities['entities']);
        self::assertSame('business.record.browse', $capabilities['list']);
        self::assertSame([
            'business.record.browse',
            'business.record.read',
            'business.record.create',
            'business.record.update',
            'business.record.history',
            'business.record.relate',
        ], $capabilities['view']);
        self::assertSame('business.record.report', $capabilities['report']);
        self::assertSame('business.record.export', $capabilities['export']);
        self::assertSame('business.record.relate', $capabilities['reorder']);
        self::assertSame('business.record.read', $capabilities['operation']);
        self::assertSame([
            'business.approval.request',
            'business.approval.approve',
            'business.approval.manage',
        ], $capabilities['approvals']);
        self::assertSame($capabilities['approvals'], $capabilities['approval']);
    }

    /**
     * Require replay identities on mutations and optimistic versions on existing-record writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryMutationRequiresAnOperationIdAndEveryExistingRecordWriteRequiresAVersion(): void
    {
        self::assertSame([
            'create', 'update', 'archive', 'restore', 'delete', 'action', 'request-action', 'relate', 'unrelate',
            'reorder',
        ], $this->constant('MUTATION_ACTIONS'));
        self::assertSame([
            'update', 'archive', 'restore', 'delete', 'action', 'request-action', 'relate', 'unrelate', 'reorder',
        ], $this->constant('VERSIONED_ACTIONS'));

        $options = $this->constant('ACTION_OPTIONS');
        foreach ($this->constant('MUTATION_ACTIONS') as $action) {
            self::assertContains('operation-id', $options[$action], $action . ' must require an operation identity.');
        }
        foreach ($this->constant('VERSIONED_ACTIONS') as $action) {
            self::assertContains('expected-version', $options[$action], $action . ' must require a record version.');
        }
    }

    /**
     * Keep every CLI action mapped to the matching shared surface operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryActionUsesItsExactSharedSurfaceOperation(): void
    {
        $operations = [
            'entities' => BusinessSurfaceOperation::Discover,
            'schema' => BusinessSurfaceOperation::Read,
            'list' => BusinessSurfaceOperation::Browse,
            'get' => BusinessSurfaceOperation::Read,
            'create' => BusinessSurfaceOperation::Create,
            'update' => BusinessSurfaceOperation::Update,
            'archive' => BusinessSurfaceOperation::Archive,
            'restore' => BusinessSurfaceOperation::Restore,
            'delete' => BusinessSurfaceOperation::Delete,
            'action' => BusinessSurfaceOperation::Action,
            'request-action' => BusinessSurfaceOperation::Approval,
            'history' => BusinessSurfaceOperation::History,
            'relate' => BusinessSurfaceOperation::Relation,
            'unrelate' => BusinessSurfaceOperation::Relation,
            'reorder' => BusinessSurfaceOperation::Reorder,
            'report' => BusinessSurfaceOperation::Report,
            'export' => BusinessSurfaceOperation::Export,
        ];
        $method = (new ReflectionClass(ManageBusinessRecordsCommand::class))->getMethod('surfaceOperation');

        foreach ($operations as $action => $operation) {
            self::assertSame($operation, $method->invoke(null, $action));
        }
    }

    /**
     * Admit business values, queries and custom inputs only through protected files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBusinessDataCanOnlyEnterThroughProtectedFileOptions(): void
    {
        $options = $this->constant('ACTION_OPTIONS');

        self::assertContains('values-file', $options['create']);
        self::assertContains('values-file', $options['update']);
        self::assertContains('query-file', $options['list']);
        self::assertContains('query-file', $options['view']);
        self::assertContains('parameters-file', $options['view']);
        self::assertContains('query-file', $options['get']);
        self::assertContains('query-file', $options['report']);
        self::assertContains('query-file', $options['export']);
        self::assertContains('target-values-file', $options['relate']);
        self::assertContains('ordered-records-file', $options['reorder']);
        foreach ($options as $actionOptions) {
            self::assertNotContains('values', $actionOptions);
            self::assertNotContains('query', $actionOptions);
            self::assertNotContains('organization', $actionOptions);
        }
    }

    /**
     * Parse single-record projections and includes through the shared bounded query factory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGetUsesTheSharedBoundedProjectionAndIncludesGrammar(): void
    {
        $method = (new ReflectionClass(ManageBusinessRecordsCommand::class))->getMethod('readSpecification');
        $query = $method->invoke($this->command([]), [
            'projection' => [
                'fields' => ['name'],
                'includes' => ['members'],
            ],
            'include_archived' => true,
            'include_deleted' => false,
        ]);

        self::assertSame(['name'], $query->projection->fields);
        self::assertSame(['members'], $query->projection->includes);
        self::assertTrue($query->includeArchived);
        self::assertFalse($query->includeDeleted);
    }

    /**
     * Refuse aggregate projections on a single-record read instead of discarding them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGetRejectsAggregatesInsteadOfSilentlyIgnoringThem(): void
    {
        $method = (new ReflectionClass(ManageBusinessRecordsCommand::class))->getMethod('readSpecification');

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($this->command([]), [
            'projection' => [
                'aggregates' => [[
                    'alias' => 'row_count',
                    'function' => 'count',
                    'field' => null,
                ]],
            ],
        ]);
    }

    /**
     * Prevent status and approval lookups from accepting caller-selected resource scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStatusAndApprovalQueriesDoNotAcceptDefinitionOrOrganizationInput(): void
    {
        $options = $this->constant('ACTION_OPTIONS');

        self::assertSame(['operation-id'], $options['operation']);
        self::assertSame(['limit'], $options['approvals']);
        self::assertSame(['approval-request'], $options['approval']);
    }

    /**
     * Derive organization identifiers only for definition scopes that include that dimension.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrganizationIsDerivedOnlyForDefinitionScopesThatRequireIt(): void
    {
        $method = (new ReflectionClass(ManageBusinessRecordsCommand::class))->getMethod('organization');
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership('acme'),
        );

        self::assertNull($method->invoke(null, $context, ['scope' => 'installation']));
        self::assertNull($method->invoke(null, $context, ['scope' => 'site']));
        self::assertSame('acme', $method->invoke(null, $context, ['scope' => 'organization']));
        self::assertSame('acme', $method->invoke(null, $context, ['scope' => 'site_organization']));
    }

    /**
     * Map an unsupported action to stable JSON without reflecting submitted text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedActionReturnsStableJsonWithoutEchoingInput(): void
    {
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(64, $this->command([])->execute(['unsupported-super-secret'], $output));
        self::assertSame([], $output->lines);
        $error = json_decode($output->errors[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(false, $error['ok']);
        self::assertSame('invocation.invalid', $error['error']['code']);
        self::assertStringNotContainsString('super-secret', $output->errors[0]);
    }

    /**
     * Reject inline values and caller-selected organization scope before authorization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInlineValuesAndSubmittedOrganizationAreRejectedBeforeAuthorization(): void
    {
        $inline = new GeneratedBusinessParityOutput();
        $organization = new GeneratedBusinessParityOutput();

        self::assertSame(64, $this->command([])->execute([
            'create',
            '--values={"credential":"super-secret"}',
        ], $inline));
        self::assertSame(64, $this->command([])->execute([
            'entities',
            '--organization=untrusted',
        ], $organization));
        self::assertStringNotContainsString('super-secret', $inline->errors[0]);
        self::assertStringNotContainsString('untrusted', $organization->errors[0]);
    }

    /**
     * Fail a create before dispatch when its replay identity is absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateFailsClosedWhenOperationIdIsMissing(): void
    {
        $token = $this->file('verified-token');
        $values = $this->file('{}');
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(64, $this->command(['business.record.create'])->execute([
            'create',
            '--site=default',
            '--token-file=' . $token,
            '--definition=site.default.invoice',
            '--values-file=' . $values,
        ], $output));
        $error = json_decode($output->errors[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invocation.invalid', $error['error']['code']);
    }

    /**
     * Fail an existing-record mutation before dispatch when its expected version is absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVersionedMutationFailsClosedWhenExpectedVersionIsMissing(): void
    {
        $token = $this->file('verified-token');
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(64, $this->command(['business.record.archive'])->execute([
            'archive',
            '--site=default',
            '--token-file=' . $token,
            '--definition=site.default.invoice',
            '--record=invoice-1',
            '--operation-id=archive-operation-0001',
        ], $output));
        $error = json_decode($output->errors[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invocation.invalid', $error['error']['code']);
    }

    /**
     * Fail a caller-bound operation lookup when its operation identity is absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperationLookupFailsClosedWhenOperationIdIsMissing(): void
    {
        $token = $this->file('verified-token');
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(64, $this->command(['business.record.read'])->execute([
            'operation',
            '--site=default',
            '--token-file=' . $token,
        ], $output));
        $error = json_decode($output->errors[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invocation.invalid', $error['error']['code']);
    }

    /**
     * Deny an unauthorized mutation before its protected values path is opened or disclosed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizationFailsBeforeAProtectedBusinessDocumentIsOpened(): void
    {
        $token = $this->file('verified-token');
        $output = new GeneratedBusinessParityOutput();

        self::assertSame(77, $this->command([])->execute([
            'create',
            '--site=default',
            '--token-file=' . $token,
            '--definition=site.default.invoice',
            '--values-file=/private/super-secret-values.json',
            '--operation-id=create-operation-0001',
        ], $output));
        $error = json_decode($output->errors[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('authorization.denied', $error['error']['code']);
        self::assertStringNotContainsString('super-secret', $output->errors[0]);
    }

    /**
     * Keep the released command name and a non-empty operator description.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCommandNameAndDescriptionAreStable(): void
    {
        $command = $this->command([]);

        self::assertSame('business-record', $command->name());
        self::assertNotSame('', trim($command->description()));
    }

    /**
     * Read one private command registry constant for structural assertions.
     *
     * @param   string  $name  Constant name declared by the command.
     *
     * @return  array<array-key, mixed>  Exact registry value.
     *
     * @since   2.0.0
     */
    private function constant(string $name): array
    {
        $constant = (new ReflectionClass(ManageBusinessRecordsCommand::class))->getConstant($name);
        self::assertIsArray($constant);

        return $constant;
    }

    /**
     * Construct a command whose token resolves to one exact capability set.
     *
     * @param   list<string>  $capabilities  Capabilities granted to the recording principal.
     *
     * @return  ManageBusinessRecordsCommand  Command with inert shared-service collaborators.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): ManageBusinessRecordsCommand
    {
        $tokens = $this->createStub(AccessTokenVerifier::class);
        $tokens->method('verify')->willReturn(AuthorizationContext::principal($capabilities, self::ACTOR));
        $records = (new ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        $surfaces = (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor();
        $catalog = (new ReflectionClass(BusinessSurfaceCatalog::class))->newInstanceWithoutConstructor();
        $operations = (new ReflectionClass(BusinessOperationStatusService::class))->newInstanceWithoutConstructor();
        $approvals = (new ReflectionClass(BusinessApprovalSurfaceService::class))->newInstanceWithoutConstructor();

        return new ManageBusinessRecordsCommand(
            $records,
            $surfaces,
            $catalog,
            new BusinessRecordQueryFactory(),
            new BusinessRecordProjector(),
            $operations,
            $approvals,
            new ConsoleAuthorizer($tokens),
            new BusinessRecordConsolePresenter(),
            new BusinessConsoleFailureMapper(),
        );
    }

    /**
     * Create one owner-protected temporary CLI input file.
     *
     * @param   string  $contents  Exact bytes to store.
     *
     * @return  string  Absolute protected path.
     *
     * @since   2.0.0
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-record-command-');
        self::assertIsString($file);
        $this->files[] = $file;
        self::assertTrue(chmod($file, 0600));
        self::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }
}
