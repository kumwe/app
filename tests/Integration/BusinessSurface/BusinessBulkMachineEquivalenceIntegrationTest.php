<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Delivery\Console\Command\BusinessBulkCommand;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves an agent applies the generated bulk form's atomic archive and restore on REST, the console and MCP.
 *
 * Each surface archives two records at their reviewed versions and restores them again through
 * `BusinessSurfaceService::bulk()`, so the result documents, the deterministic child operation identities and the
 * resulting record lifecycle are the same on every surface; MCP plans the bulk first, as every generated-business
 * MCP write is planned. A selection with one stale reviewed version is refused as a conflict on every surface and
 * leaves every member untouched, and a repeated MCP operation replays. The same assertions run on MariaDB and
 * PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordApiHandler::class)]
#[CoversClass(BusinessBulkCommand::class)]
#[CoversClass(BusinessMcpHandlers::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(BusinessMutationPlanService::class)]
#[CoversClass(BusinessSurfaceService::class)]
final class BusinessBulkMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities the agent holds on every surface.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array AGENT = ['business.record.read', 'business.record.archive', 'business.record.restore'];

    /**
     * Harness of the running test.
     *
     * @var    ?MachineSurfaceHarness
     * @since  2.0.0
     */
    private ?MachineSurfaceHarness $harness = null;

    /**
     * Arguments, including the plan, of the last MCP bulk call, so a repeat can present them again.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $lastMcpBulk = [];

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
     * Bulk archive then bulk restore yield the same documents and lifecycle on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceArchivesAndRestoresASelectionAtomically(): void
    {
        [$container, $harness, $definition] = $this->boot();
        $traces = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $token = $harness->token($surface, self::AGENT);
            $records = [$this->record($container, $definition), $this->record($container, $definition)];
            $archiveKey = 'bulk-parity-archive-' . bin2hex(random_bytes(8));
            $archived = $this->bulk($harness, $surface, $token, $definition, 'archive', $records, 1, $archiveKey);
            $archiveCall = $this->lastMcpBulk;
            $archivedState = $this->states($container, $definition, $records);
            $restored = $this->bulk(
                $harness,
                $surface,
                $token,
                $definition,
                'restore',
                $records,
                2,
                'bulk-parity-restore-' . bin2hex(random_bytes(8)),
            );
            $traces[$surface] = [
                'archive' => [$archived['operation'] ?? null, $archived['count'] ?? null],
                'children' => count(array_unique(array_column($archived['items'] ?? [], 'operation_id'))),
                'archived' => $archivedState,
                'restore' => [$restored['operation'] ?? null, $restored['count'] ?? null],
                'restored' => $this->states($container, $definition, $records),
            ];
            if ($surface === 'mcp') {
                $again = $harness->mcp($token, 'kumwe_business_bulk', $archiveCall);
                self::assertFalse($again['error'], (string) json_encode($again));
                self::assertSame($archived, $again['value'], 'A repeated MCP bulk did not replay.');
            }
        }

        foreach ($traces as $surface => $trace) {
            self::assertSame([
                'archive' => ['archive', 2],
                'children' => 2,
                'archived' => [[2, true], [2, true]],
                'restore' => ['restore', 2],
                'restored' => [[3, false], [3, false]],
            ], $trace, $surface . ' diverged from the bulk form.');
        }
    }

    /**
     * One stale reviewed version refuses the whole selection on every surface and changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleMemberRollsTheWholeSelectionBackOnEverySurface(): void
    {
        [$container, $harness, $definition] = $this->boot();
        $outcomes = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $token = $harness->token($surface, self::AGENT);
            $records = [$this->record($container, $definition), $this->record($container, $definition)];
            $items = [
                ['record_id' => $records[0], 'expected_version' => 1],
                ['record_id' => $records[1], 'expected_version' => 7],
            ];
            $key = 'bulk-parity-stale-' . bin2hex(random_bytes(8));
            $outcomes[$surface] = [
                'refusal' => match ($surface) {
                    'rest' => $harness->rest(
                        $token,
                        'POST',
                        '/api/v1/business/records/' . rawurlencode($definition) . '/bulk',
                        ['operation' => 'archive', 'items' => $items],
                        ['Idempotency-Key' => $key],
                    )['status'],
                    'cli' => $harness->cli(BusinessBulkCommand::class, $token, [
                        'archive',
                        '--definition=' . $definition,
                        '--items=' . json_encode($items, JSON_THROW_ON_ERROR),
                        '--operation-id=' . $key,
                    ])['status'],
                    default => $this->mcpRefusal($harness, $token, $definition, $records, $key),
                },
                'untouched' => $this->states($container, $definition, $records),
            ];
        }

        self::assertSame(['refusal' => 412, 'untouched' => [[1, false], [1, false]]], $outcomes['rest']);
        self::assertSame(['refusal' => 73, 'untouched' => [[1, false], [1, false]]], $outcomes['cli']);
        self::assertSame(
            ['refusal' => 'conflict.version', 'untouched' => [[1, false], [1, false]]],
            $outcomes['mcp'],
        );
    }

    /**
     * Boot the kernel, a harness and one installed neutral definition.
     *
     * @return  array{Container, MachineSurfaceHarness, string}  Kernel, harness and definition handle.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'bulk-parity');
        $this->harness->enterOrganization('machine-parity');
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $installed = NeutralBusinessFixture::install(
            $container,
            TestKernelFactory::administratorContext($container),
            NeutralBusinessFixture::document('bulk' . $suffix, Uuid::uuid7()->toString()),
        );

        return [$container, $this->harness, $installed->handle];
    }

    /**
     * Create one record as the administrator.
     *
     * @param   Container  $container   Kernel.
     * @param   string     $definition  Definition handle.
     *
     * @return  string  Record identity.
     *
     * @since   2.0.0
     */
    private function record(Container $container, string $definition): string
    {
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $record = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            TestKernelFactory::administratorContext($container),
            $definition,
            NeutralBusinessFixture::recordValues('Bulk parity ' . substr($record, -12)),
            NeutralBusinessFixture::idempotencyKey('bulk-parity-' . substr(str_replace('-', '', $record), -20)),
            recordId: $record,
        ));

        return $record;
    }

    /**
     * Apply one bulk mutation on one surface and answer its result document.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $surface     `rest`, `cli` or `mcp`.
     * @param   string                 $token       Surface token.
     * @param   string                 $definition  Definition handle.
     * @param   string                 $operation   `archive` or `restore`.
     * @param   list<string>           $records     Selected records.
     * @param   int                    $version     Reviewed version of every record.
     * @param   string                 $key         Bulk identity.
     *
     * @return  array<string, mixed>  Result document.
     *
     * @since   2.0.0
     */
    private function bulk(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $definition,
        string $operation,
        array $records,
        int $version,
        string $key,
    ): array {
        $items = array_map(
            static fn (string $record): array => ['record_id' => $record, 'expected_version' => $version],
            $records,
        );
        if ($surface === 'rest') {
            $response = $harness->rest(
                $token,
                'POST',
                '/api/v1/business/records/' . rawurlencode($definition) . '/bulk',
                ['operation' => $operation, 'items' => $items],
                ['Idempotency-Key' => $key],
            );
            self::assertSame(200, $response['status'], $response['raw']);
            $document = $response['body'];
        } elseif ($surface === 'cli') {
            $response = $harness->cli(BusinessBulkCommand::class, $token, [
                $operation,
                '--definition=' . $definition,
                '--items=' . json_encode($items, JSON_THROW_ON_ERROR),
                '--operation-id=' . $key,
            ]);
            self::assertSame(0, $response['status'], $response['stderr']);
            self::assertIsArray($response['stdout']);
            $document = $response['stdout']['data'] ?? null;
        } else {
            $arguments = [
                'operationId' => $key,
                'operation' => $operation,
                'definition' => $definition,
                'items' => array_map(
                    static fn (string $record): array => ['record' => $record, 'expectedVersion' => $version],
                    $records,
                ),
            ];
            $plan = $harness->mcp($token, 'kumwe_business_plan_bulk', $arguments);
            self::assertFalse($plan['error'], (string) json_encode($plan));
            self::assertIsArray($plan['value']);
            $this->lastMcpBulk = [...$arguments, 'plan' => $plan['value']['plan']];
            $response = $harness->mcp($token, 'kumwe_business_bulk', $this->lastMcpBulk);
            self::assertFalse($response['error'], (string) json_encode($response));
            $document = $response['value'];
        }
        self::assertIsArray($document, $surface . ' answered no bulk result.');

        return $document;
    }

    /**
     * Plan and apply a stale MCP bulk archive and answer the refusal code.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $token       MCP token.
     * @param   string                 $definition  Definition handle.
     * @param   list<string>           $records     Selected records; the second carries a stale version.
     * @param   string                 $key         Bulk identity.
     *
     * @return  mixed  Stable MCP error code.
     *
     * @since   2.0.0
     */
    private function mcpRefusal(
        MachineSurfaceHarness $harness,
        string $token,
        string $definition,
        array $records,
        string $key,
    ): mixed {
        $arguments = [
            'operationId' => $key,
            'operation' => 'archive',
            'definition' => $definition,
            'items' => [
                ['record' => $records[0], 'expectedVersion' => 1],
                ['record' => $records[1], 'expectedVersion' => 7],
            ],
        ];
        $plan = $harness->mcp($token, 'kumwe_business_plan_bulk', $arguments);
        self::assertFalse($plan['error'], (string) json_encode($plan));
        self::assertIsArray($plan['value']);
        $response = $harness->mcp($token, 'kumwe_business_bulk', [...$arguments, 'plan' => $plan['value']['plan']]);
        self::assertTrue($response['error']);
        self::assertIsArray($response['value']);

        return $response['value']['code'] ?? null;
    }

    /**
     * Read each record's version and whether it is archived.
     *
     * @param   Container     $container   Kernel.
     * @param   string        $definition  Definition handle.
     * @param   list<string>  $records     Records to read.
     *
     * @return  list<array{int, bool}>  Version and archived flag per record.
     *
     * @since   2.0.0
     */
    private function states(Container $container, string $definition, array $records): array
    {
        $service = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $service);
        $states = [];
        foreach ($records as $record) {
            $view = $service->read(new ReadRecordQuery(
                TestKernelFactory::administratorContext($container),
                $definition,
                $record,
                null,
                includeArchived: true,
                includeDeleted: true,
            ));
            $states[] = [$view->version, $view->archivedAt !== null];
        }

        return $states;
    }
}
