<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Infrastructure\Session\DoctrinePortalSessionStore;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves idle expiry and failed-client isolation through real session storage on each supported engine.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineAdministratorSessionStore::class)]
#[CoversClass(DoctrinePortalSessionStore::class)]
final class SessionIdleExpiryIntegrationTest extends TestCase
{
    /**
     * Only valid activity renews inactivity, and neither surface revives an idle or absolutely expired cookie.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdleAndAbsoluteExpiryHoldForAdministratorAndPortalSessions(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $principal = AuthenticatedPrincipal::of($context);
        self::assertNotNull($principal);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $configuration = $container->get(ApplicationConfiguration::class);
        $administrator = $container->get(AdministratorSessionStore::class);
        $portal = $container->get(PortalSessionStore::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(ApplicationConfiguration::class, $configuration);
        self::assertInstanceOf(AdministratorSessionStore::class, $administrator);
        self::assertInstanceOf(PortalSessionStore::class, $portal);
        $userAgent = 'Kumwe idle-expiry test';
        $adminSession = $administrator->create($context, $userAgent);
        $portalSession = $portal->create(
            new PortalPasswordIdentity($principal, $principal->securityEpoch()),
            new PortalContext($context->site(), $context->membership()),
            $userAgent,
        );
        try {
            foreach (
                [
                ['administrator_sessions', $administrator, $adminSession->token],
                ['portal_sessions', $portal, $portalSession->cookieToken],
                ] as [$table, $store, $token]
            ) {
                $digest = hash('sha256', $token);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $activeAt = $now->modify('-30 seconds');
                $database->update($tables->raw($table), ['last_seen_at' => $activeAt], ['token_digest' => $digest], [
                    'last_seen_at' => Types::DATETIME_IMMUTABLE,
                ]);
                self::assertNull($store->find($token, 'different browser'));
                $readSeen = static fn (): string => (new DateTimeImmutable((string) $database->fetchOne(sprintf(
                    'SELECT last_seen_at FROM %s WHERE token_digest = ?',
                    $tables->quoted($table),
                ), [$digest]), new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                self::assertSame($activeAt->format('Y-m-d H:i:s'), $readSeen());
                self::assertNotNull($store->find($token, $userAgent));
                self::assertNotSame($activeAt->format('Y-m-d H:i:s'), $readSeen());

                $expiredAt = $now->modify('-' . ($configuration->sessionIdleSeconds + 1) . ' seconds');
                $database->update($tables->raw($table), ['last_seen_at' => $expiredAt], ['token_digest' => $digest], [
                    'last_seen_at' => Types::DATETIME_IMMUTABLE,
                ]);
                self::assertNull($store->find($token, $userAgent));
                self::assertSame($expiredAt->format('Y-m-d H:i:s'), $readSeen());
                $sessionId = $table === 'administrator_sessions'
                    ? $adminSession->session->id
                    : $portalSession->session->id;
                $intent = new StepUpIntent(
                    $context->actorId(),
                    $sessionId,
                    $context->site()->identifier(),
                    $context->membership()?->organization()->identifier(),
                    $context->membership()?->workspace()?->identifier(),
                    'records.approve',
                    $principal->securityEpoch(),
                );
                try {
                    $store->rotate($intent, $now);
                    self::fail('Step-up must not revive a session that went idle during the challenge.');
                } catch (StepUpRejected) {
                    self::assertSame($expiredAt->format('Y-m-d H:i:s'), $readSeen());
                }

                $database->update($tables->raw($table), [
                    'last_seen_at' => $now,
                    'expires_at' => $now->modify('-1 second'),
                ], ['token_digest' => $digest], [
                    'last_seen_at' => Types::DATETIME_IMMUTABLE,
                    'expires_at' => Types::DATETIME_IMMUTABLE,
                ]);
                self::assertNull($store->find($token, $userAgent));
            }
        } finally {
            $administrator->delete($context, $adminSession->session->id);
            $portal->delete($portalSession->session->id, $context->actorId());
        }
    }
}
