<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Localization;

use Doctrine\DBAL\Connection;
use Kumwe\App\Delivery\Console\Command\WordingCommand;
use Kumwe\App\Delivery\Http\Api\Localization\WordingApiHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves an agent manages wording overrides through REST, the console and MCP exactly as the Wording screen does.
 *
 * Each surface saves an override for its own message, finds it in the site layer, finds the message in the
 * shipped catalogue, withdraws it and withdraws it again, all through `MessageOverrideService`, so the stored
 * record shape, the `withdrawn` answers and the audit trail are the same on every surface. An ICU pattern the
 * service refuses and a credential without `localization.overrides.manage` are refused on all three surfaces.
 * The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(WordingApiHandler::class)]
#[CoversClass(WordingCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(MessageOverrideService::class)]
final class WordingMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Message each surface overrides, so the surfaces never share a record.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array MESSAGES = [
        'rest' => 'core.console.media.description',
        'cli' => 'core.console.wording.description',
        'mcp' => 'core.console.business_approval.description',
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
     * Save, list, search and withdraw yield the same documents and audit trail on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceManagesOverridesLikeTheWordingScreen(): void
    {
        [$container, $harness] = $this->boot();
        $traces = [];
        foreach (self::MESSAGES as $surface => $identifier) {
            $token = $harness->token($surface, ['localization.overrides.manage']);
            $this->withdraw($harness, $surface, $token, $identifier);
            $from = self::lastAuditPosition($container);
            $pattern = 'Parity wording ' . $surface;
            $saved = $this->save($harness, $surface, $token, $identifier, $pattern);
            $listed = $this->overrides($harness, $surface, $token);
            $found = $this->catalogue($harness, $surface, $token, $identifier);
            $traces[$surface] = [
                'saved' => [$saved['identifier'] ?? null, $saved['pattern'] ?? null, $saved['layer'] ?? null],
                'keys' => is_array($saved) ? array_keys($saved) : null,
                'listed' => in_array([$identifier, $pattern], array_map(
                    static fn (array $item): array => [$item['identifier'], $item['pattern']],
                    $listed,
                ), true),
                'found' => in_array($identifier, array_column($found, 'identifier'), true),
                'withdrawn' => $this->withdraw($harness, $surface, $token, $identifier),
                'again' => $this->withdraw($harness, $surface, $token, $identifier),
                'audit' => self::auditSince($container, $from, 'site:en-GB:' . $identifier),
            ];
        }

        foreach ($traces as $surface => $trace) {
            self::assertSame([
                'saved' => [self::MESSAGES[$surface], 'Parity wording ' . $surface, 'site'],
                'keys' => $traces['rest']['keys'],
                'listed' => true,
                'found' => true,
                'withdrawn' => true,
                'again' => false,
                'audit' => ['localization.override.write', 'localization.override.withdraw'],
            ], $trace, $surface . ' diverged from the Wording screen.');
        }
    }

    /**
     * A broken ICU pattern and a credential without the wording grant are refused on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsMatchOnEverySurface(): void
    {
        [, $harness] = $this->boot();
        $identifier = self::MESSAGES['rest'];
        $manager = [
            'rest' => $harness->token('rest', ['localization.overrides.manage']),
            'cli' => $harness->token('cli', ['localization.overrides.manage']),
            'mcp' => $harness->token('mcp', ['localization.overrides.manage']),
        ];
        $reader = [
            'rest' => $harness->token('rest', ['content.read']),
            'cli' => $harness->token('cli', ['content.read']),
            'mcp' => $harness->token('mcp', ['content.read']),
        ];
        $body = ['layer' => 'site', 'locale' => 'en-GB', 'identifier' => $identifier, 'pattern' => 'Broken {'];

        $restBroken = $harness->rest($manager['rest'], 'PUT', '/api/v1/wording/overrides', $body, [
            'Idempotency-Key' => 'wording-parity-broken-' . bin2hex(random_bytes(6)),
        ]);
        $cliBroken = $harness->cli(WordingCommand::class, $manager['cli'], [
            'save', '--locale=en-GB', '--identifier=' . $identifier, '--pattern=Broken {',
        ]);
        $mcpBroken = $harness->mcp($manager['mcp'], 'kumwe_wording_override_save', [
            'operationId' => 'wording-parity-broken-' . bin2hex(random_bytes(6)),
            ...$body,
        ]);
        $restDenied = $harness->rest($reader['rest'], 'GET', '/api/v1/wording/overrides');
        $cliDenied = $harness->cli(WordingCommand::class, $reader['cli'], ['overrides']);
        $mcpDenied = $harness->mcp($reader['mcp'], 'kumwe_wording_override_list');

        self::assertSame(422, $restBroken['status'], $restBroken['raw']);
        self::assertSame(1, $cliBroken['status']);
        self::assertTrue($mcpBroken['error']);
        self::assertIsArray($mcpBroken['value']);
        self::assertSame('request.invalid', $mcpBroken['value']['code']);
        self::assertSame(403, $restDenied['status']);
        self::assertSame(1, $cliDenied['status']);
        self::assertIsArray($mcpDenied['value']);
        self::assertSame('authorization.denied', $mcpDenied['value']['code']);
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
        $this->harness = new MachineSurfaceHarness($container, 'wording-parity');

        return [$container, $this->harness];
    }

    /**
     * Save one site-layer en-GB override on one surface.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $surface     `rest`, `cli` or `mcp`.
     * @param   string                 $token       Surface token.
     * @param   string                 $identifier  Message identifier.
     * @param   string                 $pattern     Replacement wording.
     *
     * @return  mixed  Stored override document.
     *
     * @since   2.0.0
     */
    private function save(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $identifier,
        string $pattern,
    ): mixed {
        $body = ['layer' => 'site', 'locale' => 'en-GB', 'identifier' => $identifier, 'pattern' => $pattern];

        return match ($surface) {
            'rest' => $harness->rest($token, 'PUT', '/api/v1/wording/overrides', $body, [
                'Idempotency-Key' => 'wording-parity-save-' . bin2hex(random_bytes(6)),
            ])['body'],
            'cli' => $harness->cli(WordingCommand::class, $token, [
                'save', '--layer=site', '--locale=en-GB', '--identifier=' . $identifier, '--pattern=' . $pattern,
            ])['stdout'],
            default => $harness->mcp($token, 'kumwe_wording_override_save', [
                'operationId' => 'wording-parity-save-' . bin2hex(random_bytes(6)),
                ...$body,
            ])['value'],
        };
    }

    /**
     * List the site-layer en-GB overrides on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     *
     * @return  list<array<string, mixed>>  Overrides.
     *
     * @since   2.0.0
     */
    private function overrides(MachineSurfaceHarness $harness, string $surface, string $token): array
    {
        $document = match ($surface) {
            'rest' => $harness->rest($token, 'GET', '/api/v1/wording/overrides?layer=site&locale=en-GB')['body'],
            'cli' => $harness->cli(WordingCommand::class, $token, ['overrides', '--locale=en-GB'])['stdout'],
            default => $harness->mcp($token, 'kumwe_wording_override_list', [
                'layer' => 'site',
                'locale' => 'en-GB',
            ])['value'],
        };
        self::assertIsArray($document, $surface . ' did not list.');
        self::assertIsArray($document['items'] ?? null);

        return array_values($document['items']);
    }

    /**
     * Search the en-GB shipped catalogue for one message on one surface.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $surface     `rest`, `cli` or `mcp`.
     * @param   string                 $token       Surface token.
     * @param   string                 $identifier  Message identifier searched for.
     *
     * @return  list<array<string, mixed>>  Matches.
     *
     * @since   2.0.0
     */
    private function catalogue(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $identifier,
    ): array {
        $document = match ($surface) {
            'rest' => $harness->rest(
                $token,
                'GET',
                '/api/v1/wording/catalogue?locale=en-GB&limit=5&q=' . rawurlencode($identifier),
            )['body'],
            'cli' => $harness->cli(WordingCommand::class, $token, [
                'catalogue', '--locale=en-GB', '--limit=5', '--query=' . $identifier,
            ])['stdout'],
            default => $harness->mcp($token, 'kumwe_wording_catalogue_search', [
                'locale' => 'en-GB',
                'limit' => 5,
                'query' => $identifier,
            ])['value'],
        };
        self::assertIsArray($document, $surface . ' did not search.');
        self::assertIsArray($document['items'] ?? null);

        return array_values($document['items']);
    }

    /**
     * Withdraw one site-layer en-GB override on one surface.
     *
     * @param   MachineSurfaceHarness  $harness     Harness.
     * @param   string                 $surface     `rest`, `cli` or `mcp`.
     * @param   string                 $token       Surface token.
     * @param   string                 $identifier  Message identifier.
     *
     * @return  mixed  The surface's `withdrawn` answer.
     *
     * @since   2.0.0
     */
    private function withdraw(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $identifier,
    ): mixed {
        $body = ['layer' => 'site', 'locale' => 'en-GB', 'identifier' => $identifier];
        $document = match ($surface) {
            'rest' => $harness->rest($token, 'POST', '/api/v1/wording/overrides/withdraw', $body, [
                'Idempotency-Key' => 'wording-parity-withdraw-' . bin2hex(random_bytes(6)),
            ])['body'],
            'cli' => $harness->cli(WordingCommand::class, $token, [
                'withdraw', '--locale=en-GB', '--identifier=' . $identifier,
            ])['stdout'],
            default => $harness->mcp($token, 'kumwe_wording_override_withdraw', [
                'operationId' => 'wording-parity-withdraw-' . bin2hex(random_bytes(6)),
                ...$body,
            ])['value'],
        };

        return is_array($document) ? ($document['withdrawn'] ?? null) : null;
    }

    /**
     * Read the newest audit position before a surface acts.
     *
     * @param   Container  $container  Kernel.
     *
     * @return  int  Newest recorded position, or zero.
     *
     * @since   2.0.0
     */
    private static function lastAuditPosition(Container $container): int
    {
        [$database, $tables] = self::database($container);

        return (int) $database->fetchOne(sprintf('SELECT MAX(position) FROM %s', $tables->quoted('audit_events')));
    }

    /**
     * Read the wording audit actions recorded for one override after a position.
     *
     * @param   Container  $container  Kernel.
     * @param   int        $from       Position the surface started after.
     * @param   string     $subject    Override subject identity.
     *
     * @return  list<string>  Audit actions, oldest first.
     *
     * @since   2.0.0
     */
    private static function auditSince(Container $container, int $from, string $subject): array
    {
        [$database, $tables] = self::database($container);

        return array_map('strval', $database->fetchFirstColumn(sprintf(
            'SELECT action FROM %s WHERE subject_type = ? AND subject_id = ? AND position > ? ORDER BY position',
            $tables->quoted('audit_events'),
        ), ['message_override', $subject, $from]));
    }

    /**
     * Resolve the connection and table names.
     *
     * @param   Container  $container  Kernel.
     *
     * @return  array{Connection, TableNames}  Connection and table names.
     *
     * @since   2.0.0
     */
    private static function database(Container $container): array
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return [$database, $tables];
    }
}
