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
 * Proves an agent performs the access screen's timeline and recovery work with the browser's authority.
 *
 * The administrator access screen reads the security-event timeline and, per account, retires second factors,
 * ends sessions, removes a role, removes a grant and resets a password. Each is driven here through REST, the
 * console and MCP against the real kernel with each surface's own site-bound token, and each must land on
 * `AccessControlService` exactly as the screen does: the same audit action on the same subject, the same
 * refusal for a credential without `users.manage` or a blank reason, and a replay rather than a second write
 * for a repeated idempotency key. MCP publishes no password reset because the replacement is a secret; that
 * absence is asserted too. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
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
     * Each recovery act lands on the service with the screen's audit action, and a repeated key replays.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryActsLandOnTheServiceWithTheScreensAuditAction(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $rest = $harness->token('rest', self::MANAGE);
        $cli = $harness->token('cli', self::MANAGE);
        $mcp = $harness->token('mcp', self::MANAGE);
        $outcomes = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $subject = $this->subject($access, $administrator, $surface);
            $reason = 'parity recovery through ' . $surface;
            $key = 'identity-parity-' . $surface . '-' . bin2hex(random_bytes(6));
            $stepUp = match ($surface) {
                'rest' => $harness->rest($rest, 'POST', '/api/v1/users/' . $subject . '/step-up/revoke', [
                    'reason' => $reason,
                ], ['Idempotency-Key' => $key . '-a']),
                'cli' => $harness->cli(ManageAccessCommand::class, $cli, [
                    'revoke-step-up',
                    '--user=' . $subject,
                    '--reason=' . $reason,
                ]),
                default => $harness->mcp($mcp, 'kumwe_user_step_up_revoke', [
                    'operationId' => $key . '-a',
                    'userId' => $subject,
                    'reason' => $reason,
                ]),
            };
            $sessions = match ($surface) {
                'rest' => $harness->rest($rest, 'POST', '/api/v1/users/' . $subject . '/sessions/terminate', [
                    'reason' => $reason,
                ], ['Idempotency-Key' => $key . '-b']),
                'cli' => $harness->cli(ManageAccessCommand::class, $cli, [
                    'terminate-sessions',
                    '--user=' . $subject,
                    '--reason=' . $reason,
                ]),
                default => $harness->mcp($mcp, 'kumwe_user_sessions_terminate', [
                    'operationId' => $key . '-b',
                    'userId' => $subject,
                    'reason' => $reason,
                ]),
            };
            $outcomes[$surface] = [
                'step_up' => self::resultCount($stepUp, ['revoked_credentials', 'revoked']),
                'sessions' => self::resultCount($sessions, ['sessions_terminated']),
                'audit' => self::subjectActions($access, $administrator, $subject),
            ];
        }

        $replayKey = 'identity-parity-replay-' . bin2hex(random_bytes(6));
        $replaySubject = $this->subject($access, $administrator, 'replay');
        $first = $harness->rest($rest, 'POST', '/api/v1/users/' . $replaySubject . '/sessions/terminate', [
            'reason' => 'replayed once',
        ], ['Idempotency-Key' => $replayKey]);
        $second = $harness->rest($rest, 'POST', '/api/v1/users/' . $replaySubject . '/sessions/terminate', [
            'reason' => 'replayed once',
        ], ['Idempotency-Key' => $replayKey]);
        $mcpKey = 'identity-parity-mcp-replay-' . bin2hex(random_bytes(6));
        $mcpFirst = $harness->mcp($mcp, 'kumwe_user_sessions_terminate', [
            'operationId' => $mcpKey,
            'userId' => $replaySubject,
            'reason' => 'replayed once over mcp',
        ]);
        $mcpSecond = $harness->mcp($mcp, 'kumwe_user_sessions_terminate', [
            'operationId' => $mcpKey,
            'userId' => $replaySubject,
            'reason' => 'replayed once over mcp',
        ]);

        $expected = [
            'step_up' => 0,
            'sessions' => 0,
            'audit' => ['identity.step_up.credential.revoke', 'user.sessions.terminate'],
        ];
        foreach ($outcomes as $surface => $outcome) {
            self::assertSame($expected, $outcome, $surface . ' diverged from the access screen.');
        }
        self::assertSame(200, $first['status']);
        self::assertSame($first['body'], $second['body']);
        self::assertSame('true', $second['headers']['idempotency-replayed'] ?? null);
        self::assertSame($mcpFirst, $mcpSecond);
        self::assertSame(
            ['user.sessions.terminate', 'user.sessions.terminate'],
            self::subjectActions($access, $administrator, $replaySubject),
            'Each surface wrote once; the repeated key replayed instead of terminating again.',
        );
    }

    /**
     * Removing a role or a grant through each surface removes exactly that assignment or grant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleAndGrantRemovalReachTheSameService(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $rest = $harness->token('rest', self::MANAGE);
        $cli = $harness->token('cli', self::MANAGE);
        $mcp = $harness->token('mcp', self::MANAGE);
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $subject = $this->subject($access, $administrator, $surface . '-role');
            $role = $access->createRole(
                $administrator,
                'parity_' . $surface . '_' . bin2hex(random_bytes(4)),
                'Parity ' . $surface,
            );
            $grant = $access->grant($administrator, $role, 'content.read', 'global', null);
            $access->assignRole($administrator, $subject, $role);
            $key = 'identity-parity-role-' . $surface . '-' . bin2hex(random_bytes(6));
            $roleRevoked = match ($surface) {
                'rest' => $harness->rest($rest, 'DELETE', '/api/v1/users/' . $subject . '/roles/' . $role, null, [
                    'Idempotency-Key' => $key . '-a',
                ]),
                'cli' => $harness->cli(ManageAccessCommand::class, $cli, [
                    'revoke-role',
                    '--user=' . $subject,
                    '--role=' . $role,
                ]),
                default => $harness->mcp($mcp, 'kumwe_user_role_revoke', [
                    'operationId' => $key . '-a',
                    'userId' => $subject,
                    'roleId' => $role,
                ]),
            };
            $grantRevoked = match ($surface) {
                'rest' => $harness->rest($rest, 'DELETE', '/api/v1/grants/' . $grant, null, [
                    'Idempotency-Key' => $key . '-b',
                ]),
                'cli' => $harness->cli(ManageAccessCommand::class, $cli, ['revoke-grant', '--grant=' . $grant]),
                default => $harness->mcp($mcp, 'kumwe_role_grant_revoke', [
                    'operationId' => $key . '-b',
                    'grantId' => $grant,
                ]),
            };

            self::assertTrue(self::succeeded($roleRevoked), $surface . ': ' . json_encode($roleRevoked));
            self::assertTrue(self::succeeded($grantRevoked), $surface . ': ' . json_encode($grantRevoked));
            self::assertNotContains($role, self::roleIds($access, $administrator, $subject), $surface);
            self::assertSame([], self::roleGrants($access, $administrator, $role), $surface);
        }
    }

    /**
     * The operator password reset is served by REST and the console with one audit action, never by MCP.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPasswordResetIsRestAndConsoleOnlyWithOneAuditAction(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $rest = $harness->token('rest', self::MANAGE);
        $cli = $harness->token('cli', self::MANAGE);
        $restSubject = $this->subject($access, $administrator, 'reset-rest');
        $cliSubject = $this->subject($access, $administrator, 'reset-cli');

        $restReset = $harness->rest($rest, 'POST', '/api/v1/users/' . $restSubject . '/password-reset', [
            'password' => 'a replacement passphrase for rest',
            'reason' => 'lost the old one',
        ], ['Idempotency-Key' => 'identity-parity-reset-' . bin2hex(random_bytes(6))]);
        $cliReset = $harness->cli(ManageAccessCommand::class, $cli, [
            'reset-password',
            '--user=' . $cliSubject,
            '--password-file=' . $harness->protectedFile('a replacement passphrase for the console'),
            '--reason=lost the old one',
        ]);
        $tools = array_column((new McpCapabilityCatalog())->tools(), 'name');

        self::assertSame(200, $restReset['status'], $restReset['raw']);
        self::assertSame(0, $cliReset['status'], $cliReset['stderr']);
        self::assertSame(['user.password.reset'], self::subjectActions($access, $administrator, $restSubject));
        self::assertSame(['user.password.reset'], self::subjectActions($access, $administrator, $cliSubject));
        self::assertStringNotContainsString('a replacement passphrase', $restReset['raw']);
        self::assertSame([], array_values(array_filter(
            $tools,
            static fn (string $tool): bool => str_contains($tool, 'password'),
        )));
    }

    /**
     * A credential without `users.manage`, and a blank reason, are refused identically on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceRefusesMissingAuthorityAndABlankReason(): void
    {
        [$container, $harness, $access, $administrator] = $this->boot();
        $subject = $this->subject($access, $administrator, 'refusal');
        $weak = ['content.read'];
        $key = 'identity-parity-refusal-' . bin2hex(random_bytes(6));

        $restDenied = $harness->rest($harness->token('rest', $weak), 'GET', '/api/v1/security-events');
        $cliDenied = $harness->cli(SecurityEventsCommand::class, $harness->token('cli', $weak), ['list']);
        $mcpDenied = $harness->mcp($harness->token('mcp', $weak), 'kumwe_security_event_list');
        $restBlank = $harness->rest($harness->token('rest', self::MANAGE), 'POST', '/api/v1/users/' . $subject
            . '/sessions/terminate', ['reason' => '   '], ['Idempotency-Key' => $key]);
        $cliBlank = $harness->cli(ManageAccessCommand::class, $harness->token('cli', self::MANAGE), [
            'terminate-sessions',
            '--user=' . $subject,
            '--reason= ',
        ]);
        $mcpBlank = $harness->mcp($harness->token('mcp', self::MANAGE), 'kumwe_user_sessions_terminate', [
            'operationId' => $key,
            'userId' => $subject,
            'reason' => '   ',
        ]);

        self::assertSame(403, $restDenied['status']);
        self::assertSame(1, $cliDenied['status']);
        self::assertTrue($mcpDenied['error']);
        self::assertIsArray($mcpDenied['value']);
        self::assertSame('authorization.denied', $mcpDenied['value']['code']);
        self::assertSame(422, $restBlank['status']);
        self::assertNotSame(0, $cliBlank['status']);
        self::assertTrue($mcpBlank['error']);
        self::assertIsArray($mcpBlank['value']);
        self::assertSame('request.invalid', $mcpBlank['value']['code']);
        self::assertSame([], self::subjectActions($access, $administrator, $subject));
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
     * Read one count from a surface's success document.
     *
     * @param   array<string, mixed>  $result  REST, console or MCP result.
     * @param   list<string>          $keys    Candidate member names across surfaces.
     *
     * @return  ?int  The count, or null when the surface refused.
     *
     * @since   2.0.0
     */
    private static function resultCount(array $result, array $keys): ?int
    {
        $document = $result['body'] ?? $result['stdout'] ?? $result['value'] ?? null;
        if (!is_array($document) || !self::succeeded($result)) {
            return null;
        }
        foreach ($keys as $key) {
            if (is_int($document[$key] ?? null)) {
                return $document[$key];
            }
        }

        return null;
    }

    /**
     * Decide whether a REST, console or MCP result succeeded.
     *
     * @param   array<string, mixed>  $result  Surface result.
     *
     * @return  bool  True for a 2xx response, exit 0 or a non-error tool result.
     *
     * @since   2.0.0
     */
    private static function succeeded(array $result): bool
    {
        if (array_key_exists('error', $result)) {
            return $result['error'] === false;
        }
        $status = $result['status'] ?? null;

        return is_int($status) && (array_key_exists('stdout', $result) ? $status === 0 : $status < 300);
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

    /**
     * List the role identifiers one user holds.
     *
     * @param   AccessControlService  $access         Access-control service.
     * @param   ExecutionContext      $administrator  Integration administrator.
     * @param   string                $user           User identifier.
     *
     * @return  list<string>  Role identifiers.
     *
     * @since   2.0.0
     */
    private static function roleIds(
        AccessControlService $access,
        ExecutionContext $administrator,
        string $user,
    ): array {
        foreach ($access->users($administrator) as $row) {
            if (($row['id'] ?? null) === $user) {
                $roles = $row['roles'] ?? [];

                return array_values(array_map(
                    static fn (mixed $role): string => is_array($role) ? (string) ($role['id'] ?? '') : (string) $role,
                    is_array($roles) ? $roles : [],
                ));
            }
        }

        return [];
    }

    /**
     * List the grants one role still holds.
     *
     * @param   AccessControlService  $access         Access-control service.
     * @param   ExecutionContext      $administrator  Integration administrator.
     * @param   string                $role           Role identifier.
     *
     * @return  list<mixed>  Grant rows.
     *
     * @since   2.0.0
     */
    private static function roleGrants(
        AccessControlService $access,
        ExecutionContext $administrator,
        string $role,
    ): array {
        foreach ($access->roles($administrator) as $row) {
            if (($row['id'] ?? null) === $role) {
                $grants = $row['grants'] ?? [];

                return is_array($grants) ? array_values($grants) : [];
            }
        }

        return [];
    }
}
