<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSecurity;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Delivery\Console\Command\BusinessSecurityCommand;
use Kumwe\App\Delivery\Http\Api\Business\BusinessSecurityApiHandler;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticatedSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves every machine surface reads the Business Security overview the screen renders, and none can write.
 *
 * REST, the console and MCP answer the same `BusinessSecurityAdministrationService::overview()` document for the
 * same credential. The screen's writes are browser-only by design: calling the service directly with the agent's
 * own verified credential is refused because a bearer context carries no fresh human step-up proof, and nothing
 * is written. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSecurityApiHandler::class)]
#[CoversClass(BusinessSecurityCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(BusinessSecurityAdministrationService::class)]
final class BusinessSecurityMachineEquivalenceIntegrationTest extends TestCase
{
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
     * REST, the console and MCP answer one identical overview, and a machine credential cannot write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceReadsTheSameOverviewAndNoneCanWrite(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $harness = $this->harness = new MachineSurfaceHarness($container, 'business-security-parity');
        $harness->enterOrganization('machine-parity');
        $rest = $harness->token('rest', ['business.security.manage']);
        $cli = $harness->token('cli', ['business.security.manage']);
        $mcp = $harness->token('mcp', ['business.security.manage']);

        $fromRest = $harness->rest($rest, 'GET', '/api/v1/business-security');
        $fromCli = $harness->cli(BusinessSecurityCommand::class, $cli, ['overview']);
        $fromMcp = $harness->mcp($mcp, 'kumwe_business_security_overview');

        self::assertSame(200, $fromRest['status'], $fromRest['raw']);
        self::assertIsArray($fromRest['body']);
        self::assertArrayHasKey('organizations', $fromRest['body']);
        self::assertSame(0, $fromCli['status'], $fromCli['stderr']);
        self::assertSame($fromRest['body'], $fromCli['stdout']);
        self::assertFalse($fromMcp['error'], (string) json_encode($fromMcp));
        self::assertSame($fromRest['body'], $fromMcp['value']);

        $verifier = $container->get(AccessTokenVerifier::class);
        self::assertInstanceOf(ScopedAccessTokenVerifier::class, $verifier);
        $verified = $verifier->verifyScoped($rest, 'kumwe-http', 'api', 'default');
        self::assertNotNull($verified);
        $service = $container->get(BusinessSecurityAdministrationService::class);
        self::assertInstanceOf(BusinessSecurityAdministrationService::class, $service);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $organization = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE site_identifier = ? AND identifier = ?',
            $tables->quoted('organizations'),
        ), ['default', 'machine-parity']);
        self::assertIsString($organization);
        $identifier = 'machine-' . bin2hex(random_bytes(5));
        $refused = null;
        try {
            $service->createWorkspace(
                $verified->context('business-security-parity-write', AuthenticatedSurface::Api),
                $organization,
                $identifier,
                'Machine workspace',
            );
        } catch (InvalidArgumentException $exception) {
            $refused = $exception->getMessage();
        }

        self::assertSame('A fresh step-up proof is required.', $refused);
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ?',
            $tables->quoted('workspaces'),
        ), [$identifier]));
    }
}
