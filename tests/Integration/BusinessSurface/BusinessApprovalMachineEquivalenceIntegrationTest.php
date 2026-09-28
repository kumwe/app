<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\Delivery\Console\Command\BusinessApprovalCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\App\Delivery\Console\Contract\CliV2MachineContract;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Approval\ApprovalDenied;
use Kumwe\Approval\ApprovalService;
use Kumwe\Context\Value\AuthenticatedSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves an agent requests, reads and withdraws maker-checker approvals exactly as a human does, and never decides.
 *
 * A generated definition's high-impact action carries an active separation-of-duty rule. On each machine surface
 * the agent requests approval for one record, finds the request in its inbox, reads it, and cancels it — the
 * requester's own control, audited as `approval.cancel`. A request made on one surface is refused when another
 * surface tries to cancel it, as the browser binding requires. No surface publishes an approve, reject or revoke
 * operation, and even calling `ApprovalService::approve()` directly with the agent's own verified credential is
 * refused: the requester may not approve its own request and a bearer context carries no step-up proof. The same
 * assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessApprovalApiHandler::class)]
#[CoversClass(BusinessApprovalSurfaceService::class)]
#[CoversClass(BusinessApprovalCommand::class)]
#[CoversClass(BusinessMcpHandlers::class)]
#[CoversClass(KumweMcpHandlers::class)]
final class BusinessApprovalMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities the agent's token carries on every surface, including approve so refusal is not a grant gap.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array AGENT = [
        'business.record.read',
        'business.record.action',
        'business.record.transition',
        'business.approval.request',
        'business.approval.approve',
    ];

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
     * Every surface requests, lists, reads and cancels its own request with the browser's outcome.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceRequestsReadsAndCancelsItsOwnApproval(): void
    {
        [$container, $harness, $definition] = $this->boot();
        $tokens = [
            'rest' => $harness->token('rest', self::AGENT),
            'cli' => $harness->token('cli', self::AGENT),
            'mcp' => $harness->token('mcp', self::AGENT),
        ];
        $traces = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $record = $this->record($container, $definition, $surface);
            $request = $this->requestApproval($harness, $surface, $tokens[$surface], $definition, $record);
            $listed = $this->inbox($harness, $surface, $tokens[$surface]);
            $read = $this->read($harness, $surface, $tokens[$surface], $request);
            $cancelled = $this->cancel($harness, $surface, $tokens[$surface], $request);
            $afterwards = $this->read($harness, $surface, $tokens[$surface], $request);
            $traces[$surface] = [
                'listed' => in_array($request, $listed, true),
                'status' => $read['status'] ?? null,
                'action' => $read['action'] ?? null,
                'cancelled' => $cancelled,
                'after' => $afterwards['status'] ?? null,
            ];
        }

        foreach ($traces as $surface => $trace) {
            self::assertSame([
                'listed' => true,
                'status' => 'pending',
                'action' => 'business.record.action:approve',
                'cancelled' => true,
                'after' => 'cancelled',
            ], $trace, $surface . ' diverged from the requester cancel control.');
        }
    }

    /**
     * A request is bound to the surface that made it, and a repeated REST cancel key replays.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCancellationIsBoundToTheRequestingSurfaceAndReplays(): void
    {
        [$container, $harness, $definition] = $this->boot();
        $rest = $harness->token('rest', self::AGENT);
        $cli = $harness->token('cli', self::AGENT);
        $mcp = $harness->token('mcp', self::AGENT);
        $request = $this->requestApproval(
            $harness,
            'rest',
            $rest,
            $definition,
            $this->record($container, $definition, 'bound'),
        );

        $fromCli = $harness->cli(BusinessApprovalCommand::class, $cli, ['cancel', '--approval-request=' . $request]);
        $fromMcp = $harness->mcp($mcp, 'kumwe_business_approval_cancel', [
            'operationId' => 'approval-parity-foreign-' . bin2hex(random_bytes(6)),
            'approval' => $request,
        ]);
        $key = 'approval-parity-cancel-' . bin2hex(random_bytes(6));
        $first = $harness->rest($rest, 'POST', '/api/v1/business/approvals/' . $request . '/cancel', null, [
            'Idempotency-Key' => $key,
        ]);
        $replay = $harness->rest($rest, 'POST', '/api/v1/business/approvals/' . $request . '/cancel', null, [
            'Idempotency-Key' => $key,
        ]);
        $again = $harness->rest($rest, 'POST', '/api/v1/business/approvals/' . $request . '/cancel', null, [
            'Idempotency-Key' => $key . '-again',
        ]);

        self::assertSame(77, $fromCli['status']);
        $cliRefusal = json_decode($fromCli['stderr'], true);
        self::assertIsArray($cliRefusal, $fromCli['stderr']);
        self::assertSame('authorization.denied', $cliRefusal['error']['code'] ?? null);
        self::assertTrue($fromMcp['error']);
        self::assertIsArray($fromMcp['value']);
        self::assertSame('authorization.denied', $fromMcp['value']['code']);
        self::assertSame(204, $first['status'], $first['raw']);
        self::assertSame(204, $replay['status']);
        self::assertSame('true', $replay['headers']['idempotency-replayed'] ?? null);
        self::assertSame(404, $again['status'], 'A cancelled request stays cancelled: ' . $again['raw']);
        self::assertIsArray($again['body']);
        self::assertSame('urn:kumwe:problem:business-approval-not-found', $again['body']['type']);
    }

    /**
     * No machine surface can decide an approval, and the agent's own credential is refused by the workflow.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMachineActorMayRequestButNeverApprove(): void
    {
        [$container, $harness, $definition] = $this->boot();
        $rest = $harness->token('rest', self::AGENT);
        $request = $this->requestApproval(
            $harness,
            'rest',
            $rest,
            $definition,
            $this->record($container, $definition, 'decide'),
        );

        $openApi = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/api/openapi/generations/1.1.0/openapi.json',
        ), true);
        self::assertIsArray($openApi);
        foreach ($openApi['paths'] as $path => $operations) {
            self::assertIsString($path);
            if (str_contains($path, '/approvals/')) {
                self::assertDoesNotMatchRegularExpression('#/(approve|reject|revoke|vote)$#', $path);
            }
        }
        $cli = json_decode(CliV2MachineContract::json(), true);
        self::assertIsArray($cli);
        foreach ($cli['commands'] as $command) {
            if (str_contains($command['name'], 'approval') || $command['name'] === 'business-record') {
                foreach ($command['actions'] as $action) {
                    self::assertNotContains($action['name'], ['approve', 'reject', 'revoke', 'vote']);
                }
            }
        }
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            // Schema-plan approval is a single-operator installation step, not a maker-checker decision.
            self::assertDoesNotMatchRegularExpression('/approval_(approve|reject|revoke|vote|decide)/', $tool['name']);
        }

        $verifier = $container->get(AccessTokenVerifier::class);
        self::assertInstanceOf(ScopedAccessTokenVerifier::class, $verifier);
        $verified = $verifier->verifyScoped($rest, 'kumwe-http', 'api', 'default');
        self::assertNotNull($verified);
        $agent = $verified->context('approval-parity-self-approve', AuthenticatedSurface::Api);
        $workflow = $container->get(ApprovalService::class);
        self::assertInstanceOf(ApprovalService::class, $workflow);
        $refused = false;
        try {
            $workflow->approve($agent, $request, 'approving my own request');
        } catch (ApprovalDenied) {
            $refused = true;
        }

        self::assertTrue($refused, 'The workflow approved a request for the machine actor that made it.');
        $read = $harness->rest($rest, 'GET', '/api/v1/business/approvals/' . $request);
        self::assertSame(200, $read['status']);
        self::assertIsArray($read['body']);
        self::assertSame('pending', $read['body']['status']);
        self::assertSame(0, $read['body']['approval_count']);
    }

    /**
     * Boot the kernel, install the high-impact fixture definition and its approval rule, and open a harness.
     *
     * @return  array{Container, MachineSurfaceHarness, string}  Kernel, harness and definition handle.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'approval-parity');
        $this->harness->enterOrganization('machine-parity');
        $administrator = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('appr' . $suffix, Uuid::uuid7()->toString());
        $document['actions'][0]['high_impact'] = true;
        $installed = NeutralBusinessFixture::install($container, $administrator, $document);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $rule = 'approval.parity.' . $suffix;
        if (
            $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE site_identifier = ? AND resource_type = ? AND request_action = ? '
                . "AND organization_id IS NULL AND status = 'active'",
                $tables->quoted('separation_duty_rules'),
            ), ['default', 'business_record', 'business.record.action:approve']) === false
        ) {
            $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
            $database->insert($tables->raw('separation_duty_rules'), [
                'id' => Uuid::uuid7()->toString(),
                'site_identifier' => 'default',
                'organization_id' => null,
                'scope_key' => 'approval-parity:' . $suffix,
                'rule_code' => $rule,
                'resource_type' => 'business_record',
                'request_action' => 'business.record.action:approve',
                'approval_action' => 'business.approval.approve',
                'requester_role_id' => null,
                'approver_role_id' => null,
                'quorum' => 1,
                'distinct_actors' => true,
                'status' => 'active',
                'version' => 1,
                'created_by' => 'approval-parity-suite',
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'distinct_actors' => Types::BOOLEAN,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }

        return [$container, $this->harness, $installed->handle];
    }

    /**
     * Create one record the agent requests approval for.
     *
     * @param   Container  $container   Booted kernel.
     * @param   string     $definition  Definition handle.
     * @param   string     $label       Surface or purpose label.
     *
     * @return  string  Record identity.
     *
     * @since   2.0.0
     */
    private function record(Container $container, string $definition, string $label): string
    {
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $record = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            TestKernelFactory::administratorContext($container),
            $definition,
            NeutralBusinessFixture::recordValues('Approval parity ' . $label . ' ' . substr($record, -12)),
            NeutralBusinessFixture::idempotencyKey('approval-parity-' . substr(str_replace('-', '', $record), -20)),
            recordId: $record,
        ));

        return $record;
    }

    /**
     * Request approval for the fixture's high-impact action through one surface.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $surface     `rest`, `cli` or `mcp`.
     * @param   string                 $token       Surface token.
     * @param   string                 $definition  Definition handle.
     * @param   string                 $record      Record identity.
     *
     * @return  string  Approval request UUID.
     *
     * @since   2.0.0
     */
    private function requestApproval(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $definition,
        string $record,
    ): string {
        $key = 'approval-parity-request-' . $surface . '-' . bin2hex(random_bytes(6));
        if ($surface === 'rest') {
            $response = $harness->rest(
                $token,
                'POST',
                '/api/v1/business/records/' . $definition . '/' . $record . '/actions/approve/approval',
                ['input' => new \stdClass()],
                ['Idempotency-Key' => $key, 'If-Match' => '"v1"'],
            );
            self::assertSame(201, $response['status'], $response['raw']);
            self::assertIsArray($response['body']);
            $request = $response['body']['approval_request_id'] ?? null;
        } elseif ($surface === 'cli') {
            $response = $harness->cli(ManageBusinessRecordsCommand::class, $token, [
                'request-action',
                '--definition=' . $definition,
                '--record=' . $record,
                '--expected-version=1',
                '--action=approve',
                '--operation-id=' . $key,
            ]);
            self::assertSame(0, $response['status'], $response['stderr']);
            self::assertIsArray($response['stdout']);
            $request = $response['stdout']['data']['approval_request_id'] ?? null;
        } else {
            $arguments = [
                'operationId' => $key,
                'definition' => $definition,
                'record' => $record,
                'expectedVersion' => 1,
                'action' => 'approve',
                'input' => new \stdClass(),
            ];
            $plan = $harness->mcp($token, 'kumwe_business_plan_mutation', [
                ...$arguments,
                'operation' => 'request_action',
            ]);
            self::assertFalse($plan['error'], (string) json_encode($plan));
            self::assertIsArray($plan['value']);
            $response = $harness->mcp($token, 'kumwe_business_request_action', [
                ...$arguments,
                'plan' => $plan['value']['plan'],
            ]);
            self::assertFalse($response['error'], (string) json_encode($response));
            self::assertIsArray($response['value']);
            $request = $response['value']['approval_request_id'] ?? null;
        }
        self::assertIsString($request, $surface . ' created no approval request.');

        return $request;
    }

    /**
     * List the agent's approval inbox through one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     *
     * @return  list<string>  Request identifiers in the inbox.
     *
     * @since   2.0.0
     */
    private function inbox(MachineSurfaceHarness $harness, string $surface, string $token): array
    {
        $items = match ($surface) {
            'rest' => $harness->rest($token, 'GET', '/api/v1/business/approvals?limit=100')['body']['items'] ?? null,
            'cli' => $harness->cli(ManageBusinessRecordsCommand::class, $token, ['approvals', '--limit=100'])
                ['stdout']['data']['items'] ?? null,
            default => $harness->mcp($token, 'kumwe_business_approval_list', ['limit' => 100])['value']['items']
                ?? null,
        };
        self::assertIsArray($items, $surface . ' answered no inbox.');

        return array_values(array_map(
            static fn (mixed $item): string => is_array($item)
                ? (string) ($item['approval_request_id'] ?? $item['id'] ?? '')
                : '',
            $items,
        ));
    }

    /**
     * Read one approval request through one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $request  Approval request UUID.
     *
     * @return  array<string, mixed>  Request document.
     *
     * @since   2.0.0
     */
    private function read(MachineSurfaceHarness $harness, string $surface, string $token, string $request): array
    {
        $document = match ($surface) {
            'rest' => $harness->rest($token, 'GET', '/api/v1/business/approvals/' . $request)['body'],
            'cli' => $harness->cli(ManageBusinessRecordsCommand::class, $token, [
                'approval',
                '--approval-request=' . $request,
            ])['stdout']['data'] ?? null,
            default => $harness->mcp($token, 'kumwe_business_approval_get', ['approval' => $request])['value'],
        };
        self::assertIsArray($document, $surface . ' could not read the request.');

        return $document;
    }

    /**
     * Cancel one approval request through one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $request  Approval request UUID.
     *
     * @return  bool  Whether the surface reported success.
     *
     * @since   2.0.0
     */
    private function cancel(MachineSurfaceHarness $harness, string $surface, string $token, string $request): bool
    {
        return match ($surface) {
            'rest' => $harness->rest($token, 'POST', '/api/v1/business/approvals/' . $request . '/cancel', null, [
                'Idempotency-Key' => 'approval-parity-cancel-' . bin2hex(random_bytes(6)),
            ])['status'] === 204,
            'cli' => $harness->cli(BusinessApprovalCommand::class, $token, [
                'cancel',
                '--approval-request=' . $request,
            ])['status'] === 0,
            default => $harness->mcp($token, 'kumwe_business_approval_cancel', [
                'operationId' => 'approval-parity-cancel-' . bin2hex(random_bytes(6)),
                'approval' => $request,
            ])['error'] === false,
        };
    }
}
