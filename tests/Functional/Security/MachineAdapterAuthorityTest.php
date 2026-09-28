<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ManageNavigationCommand;
use Kumwe\App\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the REST, MCP and CLI adapters each accept only their own credential and borrow no authority.
 *
 * A confused deputy is an adapter that performs an action with authority the caller never presented: a
 * browser cookie honoured by the machine API, a token minted for one transport accepted by another, a site
 * named in a header that the token was never issued for, or a narrow credential that reaches a mutation
 * through a second surface. Every case here enters through the production composition — the HTTP pipeline
 * for REST and MCP, the composed console command for the CLI — with real users and real tokens.
 *
 * @since  2.0.0
 */
#[CoversClass(BearerAuthenticationMiddleware::class)]
#[CoversClass(ConsoleAuthorizer::class)]
#[CoversClass(McpHttpHandler::class)]
final class MachineAdapterAuthorityTest extends TestCase
{
    /**
     * A token minted for one adapter is refused by the other two, while its own adapter accepts it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachAdapterAcceptsOnlyTheCredentialMintedForIt(): void
    {
        $harness = SecurityHttpHarness::boot();
        $tokens = [
            'rest' => $harness->machineActor(['navigation.manage'])['token'],
            'mcp' => $harness->machineActor(['navigation.manage'], 'kumwe-mcp', 'mcp')['token'],
            'cli' => $harness->machineActor(['navigation.manage'], 'kumwe-cli', 'management')['token'],
        ];

        foreach ($tokens as $minted => $token) {
            $rest = $harness->handle($harness->api('GET', '/api/v1/menus', $token));
            self::assertSame($minted === 'rest' ? 200 : 401, $rest->getStatusCode(), $minted . ' token on REST');

            $mcp = $harness->handle($harness->mcpRequest($token, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'kumwe-security-qualification', 'version' => '1'],
                ],
            ]));
            self::assertSame($minted === 'mcp' ? 200 : 401, $mcp->getStatusCode(), $minted . ' token on MCP');

            $file = $harness->tokenFile($token);
            try {
                $cli = $harness->console(ManageNavigationCommand::class, [
                    'list',
                    '--site=default',
                    '--token-file=' . $file,
                ]);
            } finally {
                unlink($file);
            }
            self::assertSame($minted === 'cli' ? 0 : 1, $cli['status'], $minted . ' token on the CLI');
            self::assertStringNotContainsString($token, $cli['output'] . $cli['errors']);
        }
    }

    /**
     * Browser cookies, query-string tokens and foreign site headers confer no machine authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoAmbientOrMisdirectedCredentialReachesAMachineSurface(): void
    {
        $harness = SecurityHttpHarness::boot();
        $cookie = $harness->administratorCookie();
        $token = $harness->machineActor(['navigation.manage'])['token'];

        foreach (['/api/v1/menus', '/mcp'] as $path) {
            $response = $harness->handle(
                $harness->request('GET', $path)
                    ->withCookieParams($cookie)
                    ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'default'),
            );
            self::assertSame(401, $response->getStatusCode(), 'An administrator cookie is not machine authority.');
        }
        $query = $harness->handle(
            $harness->request('GET', '/api/v1/menus?access_token=' . rawurlencode($token))
                ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'default'),
        );
        self::assertSame(401, $query->getStatusCode(), 'A token is accepted only from the Authorization header.');

        $foreign = $harness->handle(
            $harness->api('GET', '/api/v1/menus', $token)
                ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'another-site'),
        );
        self::assertSame(401, $foreign->getStatusCode(), 'A token is valid only for the site it was issued in.');
        $ambiguous = $harness->handle(
            $harness->api('GET', '/api/v1/menus', $token)
                ->withAddedHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'another-site'),
        );
        self::assertContains($ambiguous->getStatusCode(), [400, 401], 'Two site headers are never resolved.');

        $browser = $harness->handle(
            $harness->request('GET', '/administrator/navigation')->withHeader('Authorization', 'Bearer ' . $token),
        );
        self::assertSame(303, $browser->getStatusCode(), 'A bearer token is not a browser session.');
        self::assertSame('/administrator/login', $browser->getHeaderLine('Location'));
        foreach ([$query, $foreign, $ambiguous] as $refusal) {
            self::assertStringNotContainsString($token, (string) $refusal->getBody());
        }
    }

    /**
     * A credential without the mutation capability cannot create through REST, MCP or the CLI.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANarrowCredentialCannotBorrowAuthorityThroughAnyAdapter(): void
    {
        $harness = SecurityHttpHarness::boot();
        $handle = 'deputy_' . bin2hex(random_bytes(6));
        $rest = $harness->machineActor(['content.read'])['token'];
        $mcp = $harness->machineActor(['content.read'], 'kumwe-mcp', 'mcp')['token'];
        $cli = $harness->machineActor(['content.read'], 'kumwe-cli', 'management')['token'];

        $created = $harness->handle($harness->api(
            'POST',
            '/api/v1/menus',
            $rest,
            ['handle' => $handle, 'title' => 'Borrowed authority'],
            ['Idempotency-Key' => 'deputy-rest-' . $handle],
        ));
        self::assertSame(403, $created->getStatusCode());

        $call = $harness->mcpCall($mcp, $harness->mcpSession($mcp), 'kumwe_menu_create', [
            'operationId' => 'deputy-mcp-' . $handle,
            'handle' => $handle,
            'title' => 'Borrowed authority',
        ]);
        self::assertTrue(
            ($call['result']['isError'] ?? false) === true || isset($call['error']),
            'The MCP tool refuses the narrow token.',
        );

        $file = $harness->tokenFile($cli);
        try {
            $console = $harness->console(ManageNavigationCommand::class, [
                'create-menu',
                '--site=default',
                '--token-file=' . $file,
                '--handle=' . $handle,
                '--title=Borrowed authority',
            ]);
        } finally {
            unlink($file);
        }
        self::assertSame(1, $console['status']);

        $navigation = $harness->container->get(NavigationService::class);
        self::assertInstanceOf(NavigationService::class, $navigation);
        $handles = [];
        foreach ($navigation->menus(TestKernelFactory::administratorContext($harness->container)) as $menu) {
            $handles[] = $menu->handle;
        }
        self::assertNotContains($handle, $handles, 'No adapter created the menu.');
    }
}
