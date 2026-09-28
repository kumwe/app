<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use Kumwe\App\Delivery\Console\Command\ManageAccessCommand;
use Kumwe\App\Delivery\Console\Command\SecurityEventsCommand;
use Kumwe\App\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves machine surfaces read the access screen's security timeline and are never weaker than its step-up gate.
 *
 * REST, the console and MCP read the same security-event timeline under `users.manage`. The screen's
 * account-recovery acts — resetting another account's password, retiring its second factors, ending its
 * sessions — run only behind a payload-bound human step-up proof, which no bearer credential carries: REST and MCP
 * publish none of them, and the retained console actions are refused by `AccessControlService` with the
 * browser's own step-up refusal, while the screen's stepped context still reaches the service. The same
 * assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlService::class)]
#[CoversClass(AccessControlApiHandler::class)]
#[CoversClass(SecurityEventsCommand::class)]
#[CoversClass(ManageAccessCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(McpCapabilityCatalog::class)]
final class IdentityRecoveryMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities a fully authorized identity-administration token carries.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array MANAGE = ['users.manage'];

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
     * Every surface reads the same security timeline the access screen renders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceReadsTheSameSecurityTimeline(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $rest = $harness->token('rest', self::MANAGE);
        $cli = $harness->token('cli', self::MANAGE);
        $mcp = $harness->token('mcp', self::MANAGE);
        $expected = $access->securityEvents($administrator);

        $restEvents = $harness->rest($rest, 'GET', '/api/v1/security-events');
        $cliEvents = $harness->cli(SecurityEventsCommand::class, $cli, ['list']);
        $mcpEvents = $harness->mcp($mcp, 'kumwe_security_event_list');

        self::assertSame(200, $restEvents['status']);
        self::assertSame('no-store', $restEvents['headers']['cache-control'] ?? null);
        self::assertSame(0, $cliEvents['status'], $cliEvents['stderr']);
        self::assertFalse($mcpEvents['error']);
        self::assertNotSame([], $expected);
        foreach ([$restEvents['body'], $cliEvents['stdout'], $mcpEvents['value']] as $document) {
            self::assertIsArray($document);
            self::assertSame(self::ids($expected), self::ids($document['items']));
        }
    }

    /**
     * The step-up-gated recovery acts are refused to a `users.manage` token on every machine surface.
     *
     * The access screen resets another account's password, retires its second factors and ends its sessions only
     * after consuming a payload-bound human step-up proof. REST publishes none of the three, MCP publishes no
     * tool for them, and the retained console actions reach `AccessControlService`, which refuses a human
     * caller without a proof with the browser's own step-up refusal. Nothing changes for the subject.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpGatedRecoveryActsAreRefusedOnEveryMachineSurface(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $rest = $harness->token('rest', self::MANAGE);
        $cli = $harness->token('cli', self::MANAGE);
        $mcp = $harness->token('mcp', self::MANAGE);
        $subject = $this->subject($access, $administrator, 'step-up-refusal');
        $refusal = 'The step-up credential is invalid, expired, or already used.';
        $acts = [
            'password-reset' => [
                ['password' => 'a replacement passphrase', 'reason' => 'no proof'],
                ['reset-password', '--password-file=' . $harness->protectedFile('a replacement passphrase')],
                'kumwe_user_password_reset',
            ],
            'step-up/revoke' => [['reason' => 'no proof'], ['revoke-step-up'], 'kumwe_user_step_up_revoke'],
            'sessions/terminate' => [['reason' => 'no proof'], ['terminate-sessions'], 'kumwe_user_sessions_terminate'],
        ];
        $tools = array_column((new McpCapabilityCatalog())->tools(), 'name');
        $document = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/api/openapi/generations/1.1.0/openapi.json',
        ), true);
        self::assertIsArray($document);

        foreach ($acts as $path => [$body, $console, $tool]) {
            $restResult = $harness->rest($rest, 'POST', '/api/v1/users/' . $subject . '/' . $path, $body, [
                'Idempotency-Key' => 'identity-parity-refused-' . bin2hex(random_bytes(6)),
            ]);
            $cliResult = $harness->cli(ManageAccessCommand::class, $cli, [
                $console[0],
                '--user=' . $subject,
                '--reason=no proof',
                ...array_slice($console, 1),
            ]);
            $mcpRefused = false;
            try {
                $harness->mcp($mcp, $tool, ['operationId' => 'identity-parity-' . bin2hex(random_bytes(8))]);
            } catch (\RuntimeException) {
                $mcpRefused = true;
            }

            self::assertContains($restResult['status'], [404, 405], $path);
            self::assertArrayNotHasKey('/api/v1/users/{id}/' . $path, $document['paths']);
            self::assertSame(1, $cliResult['status'], $path);
            self::assertSame($refusal, $cliResult['stderr'], $path);
            self::assertNotContains($tool, $tools);
            self::assertTrue($mcpRefused, $tool . ' answered as a tool.');
        }
        self::assertSame([], self::subjectActions($access, $administrator, $subject));
        self::assertNotContains('kumwe_user_role_revoke', $tools);
        self::assertNotContains('kumwe_role_grant_revoke', $tools);
    }

    /**
     * The context the access screen hands in after a consumed step-up still reaches every recovery act.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheScreensSteppedContextStillReachesTheRecoveryActs(): void
    {
        [$container, , $access, $administrator] = $this->boot();
        $subject = $this->subject($access, $administrator, 'stepped');

        $access->revokeStepUpCredentials(
            TestKernelFactory::steppedAdministratorContext($container),
            $subject,
            'stepped recovery',
        );
        $access->terminateUserSessions(
            TestKernelFactory::steppedAdministratorContext($container),
            $subject,
            'stepped recovery',
        );
        $access->resetUserPassword(
            TestKernelFactory::steppedAdministratorContext($container),
            $subject,
            'a stepped replacement passphrase',
            'stepped recovery',
        );

        self::assertSame(
            ['identity.step_up.credential.revoke', 'user.sessions.terminate', 'user.password.reset'],
            self::subjectActions($access, $administrator, $subject),
        );
    }

    /**
     * A credential without `users.manage` is refused the security timeline identically on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceRefusesTheTimelineWithoutAuthority(): void
    {
        [, $harness] = $this->boot();
        $weak = ['content.read'];

        $restDenied = $harness->rest($harness->token('rest', $weak), 'GET', '/api/v1/security-events');
        $cliDenied = $harness->cli(SecurityEventsCommand::class, $harness->token('cli', $weak), ['list']);
        $mcpDenied = $harness->mcp($harness->token('mcp', $weak), 'kumwe_security_event_list');

        self::assertSame(403, $restDenied['status']);
        self::assertSame(1, $cliDenied['status']);
        self::assertTrue($mcpDenied['error']);
        self::assertIsArray($mcpDenied['value']);
        self::assertSame('authorization.denied', $mcpDenied['value']['code']);
    }

    /**
     * Boot the kernel and a fresh harness.
     *
     * @return  array{Container, MachineSurfaceHarness, AccessControlService, ExecutionContext}  Kernel parts.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'identity-parity');
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);

        return [$container, $this->harness, $access, TestKernelFactory::administratorContext($container)];
    }

    /**
     * Create one active subject account the recovery acts address.
     *
     * @param   AccessControlService  $access         Access-control service.
     * @param   ExecutionContext      $administrator  Integration administrator.
     * @param   string                $label          Surface or purpose label.
     *
     * @return  string  Subject user identifier.
     *
     * @since   2.0.0
     */
    private function subject(AccessControlService $access, ExecutionContext $administrator, string $label): string
    {
        return $access->createUser(
            $administrator,
            'parity-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.test',
            'Parity ' . $label,
            'an original subject passphrase',
            UserStatus::Active,
        );
    }

    /**
     * List the identity security-event actions recorded for one subject, oldest first.
     *
     * @param   AccessControlService  $access         Access-control service.
     * @param   ExecutionContext      $administrator  Integration administrator.
     * @param   string                $subject        Subject user identifier.
     *
     * @return  list<string>  Actions other than the account's creation.
     *
     * @since   2.0.0
     */
    private static function subjectActions(
        AccessControlService $access,
        ExecutionContext $administrator,
        string $subject,
    ): array {
        $actions = [];
        foreach (array_reverse($access->securityEvents($administrator)) as $event) {
            if (($event['subject_id'] ?? null) === $subject && ($event['action'] ?? null) !== 'user.create') {
                $actions[] = (string) $event['action'];
            }
        }

        return $actions;
    }

    /**
     * List event identifiers in order.
     *
     * @param   mixed  $events  Event rows.
     *
     * @return  list<string>  Identifiers.
     *
     * @since   2.0.0
     */
    private static function ids(mixed $events): array
    {
        self::assertIsArray($events);

        return array_values(array_map(static fn (mixed $event): string => is_array($event)
            ? (string) ($event['id'] ?? '')
            : '', $events));
    }
}
