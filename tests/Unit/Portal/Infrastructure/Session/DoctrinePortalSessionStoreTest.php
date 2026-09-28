<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Infrastructure\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\OwnershipScope;
use Kumwe\Access\ResourceOwnership;
use Kumwe\Access\ResourceSiteOwnershipWriter;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Application\PortalSessionIdentityLoader;
use Kumwe\App\Portal\Infrastructure\Session\DoctrinePortalSessionStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the portal session store's find and rotate rejection matrix against a real SQL table.
 *
 * Every branch that turns a presented cookie or a step-up intent away is exercised here over an
 * in-memory SQLite `portal_sessions` table: malformed tokens, unknown tokens, absolute expiry, idle
 * expiry, a foreign browser, an identity the live loader no longer vouches for, a membership or policy
 * generation that moved on, an organization or workspace that changed, and a security epoch that was
 * bumped. Rotation additionally refuses an intent naming another user, another site, another
 * organization or workspace, or an epoch the row does not carry, and refuses when the old row vanished
 * between the locked read and the delete. The happy paths prove what a refusal must not touch.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrinePortalSessionStore::class)]
final class DoctrinePortalSessionStoreTest extends TestCase
{
    /**
     * Subject UUID of the portal member whose sessions are stored.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string USER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb510';

    /**
     * Subject UUID of a different member, used to prove ownership checks.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OTHER_USER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb511';

    /**
     * Membership row UUID the stored context refers to.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string MEMBERSHIP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb512';

    /**
     * Browser user agent every session in these tests is bound to.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string USER_AGENT = 'Kumwe portal session test browser';

    /**
     * Instant every store in these tests reads as now.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string NOW = '2026-09-24T10:00:00+00:00';

    /**
     * The shape check runs before any lookup, so a malformed token resolves nothing even when a row matches it.
     *
     * @param   string  $token  Cookie value outside the accepted token grammar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenResolvesNothingEvenWhenARowCarriesItsDigest(string $token): void
    {
        $fixture = $this->fixture();
        $fixture->database->update(
            $fixture->tables->raw('portal_sessions'),
            ['token_digest' => hash('sha256', $token)],
            ['id' => $fixture->session->id],
        );

        self::assertNull($fixture->store->find($token, self::USER_AGENT));
        self::assertSame([], $fixture->loader->calls, 'A malformed token must never reach the identity loader.');
    }

    /**
     * Supply one token per rejected shape: too short, too long, and outside the URL-safe alphabet.
     *
     * @return  iterable<string, array{string}>  Named malformed tokens.
     *
     * @since   2.0.0
     */
    public static function malformedTokens(): iterable
    {
        yield 'one character short of the minimum' => [str_repeat('a', 42)];
        yield 'one character over the maximum' => [str_repeat('a', 513)];
        yield 'base64 padding rather than the URL-safe alphabet' => [str_repeat('a', 43) . '='];
        yield 'a newline smuggled into a valid-length token' => [str_repeat('a', 43) . "\n"];
        yield 'an empty cookie value' => [''];
    }

    /**
     * A well-formed token nobody issued resolves nothing and touches nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnknownTokenResolvesNothing(): void
    {
        $fixture = $this->fixture();

        self::assertNull($fixture->store->find(str_repeat('z', 64), self::USER_AGENT));
        self::assertSame([], $fixture->loader->calls);
        self::assertSame(self::NOW, $fixture->lastSeenAt());
    }

    /**
     * An absolutely expired session is refused without renewing its activity or consulting the loader.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExpiredSessionIsRefusedWithoutRenewal(): void
    {
        $fixture = $this->fixture();
        $fixture->stamp('last_seen_at', '-10 minutes');
        $fixture->stamp('expires_at', '-1 second');

        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
        self::assertSame([], $fixture->loader->calls);
        self::assertSame('2026-09-24T09:50:00+00:00', $fixture->lastSeenAt());
    }

    /**
     * A session that reaches the expiry instant exactly is already expired, not still live.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASessionExpiringAtThisVeryInstantIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->stamp('expires_at', '+0 seconds');

        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
    }

    /**
     * Idle expiry is judged at the exact boundary: the idle limit itself is refused, one second less is renewed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdleExpiryIsJudgedAtTheExactBoundary(): void
    {
        $fixture = $this->fixture();
        $fixture->stamp('last_seen_at', '-1800 seconds');
        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
        self::assertSame('2026-09-24T09:30:00+00:00', $fixture->lastSeenAt(), 'A refused cookie renews nothing.');

        $fixture->stamp('last_seen_at', '-1799 seconds');
        self::assertInstanceOf(PortalSession::class, $fixture->store->find($fixture->cookieToken, self::USER_AGENT));
        self::assertSame(self::NOW, $fixture->lastSeenAt(), 'Valid activity renews the inactivity clock.');
    }

    /**
     * A token replayed from another browser resolves nothing, and the loader is never asked about it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignBrowserIsRefusedBeforeTheIdentityIsLoaded(): void
    {
        $fixture = $this->fixture();

        self::assertNull($fixture->store->find($fixture->cookieToken, 'Some other browser'));
        self::assertNull($fixture->store->find($fixture->cookieToken, ''));
        self::assertSame([], $fixture->loader->calls);
    }

    /**
     * An identity the live loader no longer vouches for is refused, whatever the row still says.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIdentityTheLoaderNoLongerVouchesForIsRefused(): void
    {
        $fixture = $this->fixture();
        $fixture->loader->identity = null;

        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
        self::assertSame([[
            self::USER,
            SiteContext::DEFAULT,
            'acme',
            self::MEMBERSHIP,
            'north',
            $fixture->session->id,
        ]], $fixture->loader->calls, 'The loader is asked about exactly the coordinates the row stores.');
        self::assertSame(self::NOW, $fixture->lastSeenAt());
    }

    /**
     * Every live coordinate that drifts from the stored row refuses the cookie.
     *
     * @param   string  $drift  Which coordinate the live identity no longer agrees on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedCoordinates')]
    public function testALiveCoordinateThatDriftedFromTheRowIsRefused(string $drift): void
    {
        $fixture = $this->fixture();
        $fixture->loader->identity = $fixture->identity($drift);

        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
        self::assertCount(1, $fixture->loader->calls);
    }

    /**
     * Name each coordinate `contextMatchesRow()` compares, plus the epoch the identity itself carries.
     *
     * @return  iterable<string, array{string}>  Named drifts.
     *
     * @since   2.0.0
     */
    public static function driftedCoordinates(): iterable
    {
        yield 'membership version moved on' => ['membership_version'];
        yield 'policy generation moved on' => ['policy_generation'];
        yield 'organization changed' => ['organization'];
        yield 'workspace changed' => ['workspace'];
        yield 'membership row changed' => ['membership_id'];
        yield 'membership withdrawn entirely' => ['no_membership'];
        yield 'security epoch bumped' => ['security_epoch'];
    }

    /**
     * A live cookie resolves the stored session exactly and renews only its inactivity clock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALiveCookieResolvesTheStoredSessionAndRenewsActivity(): void
    {
        $fixture = $this->fixture();
        $fixture->stamp('last_seen_at', '-5 minutes');

        $session = $fixture->store->find($fixture->cookieToken, self::USER_AGENT);

        self::assertInstanceOf(PortalSession::class, $session);
        self::assertSame($fixture->session->id, $session->id);
        self::assertSame($fixture->session->csrfToken, $session->csrfToken);
        self::assertSame(self::USER, $session->identity->principal->subject());
        self::assertSame(1, $session->identity->securityEpoch);
        self::assertNull($session->stepUpAt);
        self::assertSame(
            $fixture->session->expiresAt->format(DATE_ATOM),
            $session->expiresAt->format(DATE_ATOM),
        );
        self::assertSame(self::NOW, $fixture->lastSeenAt());
    }

    /**
     * Rotation refuses an intent naming a session that does not exist or belongs to another member.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotationRefusesAnUnknownSessionOrAnotherMembersSession(): void
    {
        $fixture = $this->fixture();

        foreach (
            [
            $fixture->intent(sessionId: '018f22e2-7c8b-7ab0-8f3a-88e8026bb599'),
            $fixture->intent(subjectId: self::OTHER_USER),
            ] as $intent
        ) {
            try {
                $fixture->store->rotate($intent, $fixture->now);
                self::fail('Rotation must refuse a session the intent does not own.');
            } catch (StepUpRejected) {
                self::assertSame([], $fixture->loader->calls, 'No identity is loaded for a row that was not found.');
            }
        }
        self::assertSame(1, $fixture->rows());
        self::assertSame([], $fixture->ownership->recorded);
        self::assertSame([], $fixture->ownership->removed);
    }

    /**
     * Rotation refuses a session that expired or went idle during the challenge.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotationRefusesAnExpiredOrIdleSession(): void
    {
        $fixture = $this->fixture();

        $fixture->stamp('expires_at', '-1 second');
        $this->expectRotationRefused($fixture);

        $fixture->stamp('expires_at', '+7 hours');
        $fixture->stamp('last_seen_at', '-1800 seconds');
        $this->expectRotationRefused($fixture);

        self::assertSame(1, $fixture->rows());
        self::assertSame($fixture->session->id, $fixture->onlyRowId());
    }

    /**
     * Rotation refuses when the live loader withdraws the identity or any stored coordinate drifted.
     *
     * @param   string  $drift  Which coordinate the live identity no longer agrees on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedCoordinates')]
    public function testRotationRefusesADriftedLiveIdentity(string $drift): void
    {
        $fixture = $this->fixture();
        $fixture->loader->identity = $fixture->identity($drift);

        $this->expectRotationRefused($fixture);

        $fixture->loader->identity = null;
        $this->expectRotationRefused($fixture);
        self::assertSame(1, $fixture->rows());
    }

    /**
     * Rotation refuses an intent whose epoch, site, organization or workspace is not the session's.
     *
     * The intent is what the step-up challenge was bound to. A challenge answered for one scope must
     * not rotate a session that meanwhile lives in another, so each coordinate is compared exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotationRefusesAnIntentBoundToAnotherScopeOrEpoch(): void
    {
        $fixture = $this->fixture();

        foreach (
            [
            'security epoch' => $fixture->intent(securityEpoch: 2),
            'site' => $fixture->intent(siteIdentifier: 'corporate'),
            'organization' => $fixture->intent(organizationIdentifier: 'globex'),
            'organization withdrawn' => $fixture->intent(organizationIdentifier: null, workspaceIdentifier: null),
            'workspace' => $fixture->intent(workspaceIdentifier: 'south'),
            'workspace withdrawn' => $fixture->intent(workspaceIdentifier: null),
            ] as $case => $intent
        ) {
            try {
                $fixture->store->rotate($intent, $fixture->now);
                self::fail(sprintf('Rotation must refuse an intent whose %s differs from the session.', $case));
            } catch (StepUpRejected) {
                self::assertSame(1, $fixture->rows(), $case);
            }
        }
        self::assertSame([], $fixture->ownership->recorded);
    }

    /**
     * A row that disappears between the locked read and the delete refuses the rotation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotationRefusesWhenTheOldRowVanishedBeforeTheDelete(): void
    {
        $fixture = $this->fixture();
        $fixture->loader->sideEffect = static function () use ($fixture): void {
            $fixture->database->delete($fixture->tables->raw('portal_sessions'), ['id' => $fixture->session->id]);
        };

        $this->expectRotationRefused($fixture);
    }

    /**
     * A successful rotation issues fresh secrets, keeps expiry and browser binding, and retires the old cookie.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotationReplacesTheSessionWithFreshSecretsAndRetiresTheOldCookie(): void
    {
        $fixture = $this->fixture();
        $verifiedAt = $fixture->now->modify('+2 minutes');

        $rotated = $fixture->store->rotate($fixture->intent(), $verifiedAt);

        self::assertNotSame($fixture->session->id, $rotated->sessionId);
        self::assertNotSame($fixture->cookieToken, $rotated->cookieToken);
        self::assertNotSame($fixture->session->csrfToken, $rotated->csrfToken);
        self::assertSame(
            $fixture->session->expiresAt->format(DATE_ATOM),
            $rotated->expiresAt->format(DATE_ATOM),
            'Step-up never extends the absolute lifetime.',
        );
        self::assertSame(1, $fixture->rows());
        self::assertSame($rotated->sessionId, $fixture->onlyRowId());
        self::assertSame([[$fixture->session->id, SiteContext::DEFAULT]], $fixture->ownership->removed);
        self::assertSame([[$rotated->sessionId, SiteContext::DEFAULT]], $fixture->ownership->recorded);

        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT), 'The old cookie is dead.');
        self::assertNull($fixture->store->find($rotated->cookieToken, 'Some other browser'), 'Binding carries over.');
        $replacement = $fixture->store->find($rotated->cookieToken, self::USER_AGENT);
        self::assertInstanceOf(PortalSession::class, $replacement);
        self::assertSame($rotated->sessionId, $replacement->id);
        self::assertSame($rotated->csrfToken, $replacement->csrfToken);
        self::assertSame($verifiedAt->format(DATE_ATOM), $replacement->stepUpAt?->format(DATE_ATOM));
        self::assertSame(
            $fixture->session->authenticatedAt->format(DATE_ATOM),
            $replacement->authenticatedAt->format(DATE_ATOM),
            'The original sign-in instant survives rotation.',
        );
    }

    /**
     * Deletion removes only a session its authenticated owner names, together with its ownership row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeletionRequiresTheOwnerAndRemovesOwnershipWithTheRow(): void
    {
        $fixture = $this->fixture();

        $fixture->store->delete($fixture->session->id, self::OTHER_USER);
        self::assertSame(1, $fixture->rows());
        self::assertSame([], $fixture->ownership->removed);

        $fixture->store->delete($fixture->session->id, self::USER);
        self::assertSame(0, $fixture->rows());
        self::assertSame([[$fixture->session->id, SiteContext::DEFAULT]], $fixture->ownership->removed);
        self::assertNull($fixture->store->find($fixture->cookieToken, self::USER_AGENT));
    }

    /**
     * Purging removes only rows past their absolute expiry, each with its ownership row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPurgeRemovesOnlyExpiredRows(): void
    {
        $fixture = $this->fixture();
        $expired = $fixture->store->create(
            new PortalPasswordIdentity($fixture->principal, 1),
            new PortalContext(SiteContext::default(), $fixture->membership()),
            self::USER_AGENT,
        );
        $fixture->database->update(
            $fixture->tables->raw('portal_sessions'),
            ['expires_at' => $fixture->now->modify('-1 second')],
            ['id' => $expired->session->id],
            ['expires_at' => Types::DATETIME_IMMUTABLE],
        );

        self::assertSame(1, $fixture->store->purgeExpired());
        self::assertSame(1, $fixture->rows());
        self::assertSame($fixture->session->id, $fixture->onlyRowId());
        self::assertSame([[$expired->session->id, SiteContext::DEFAULT]], $fixture->ownership->removed);
    }

    /**
     * Refuse construction outside the documented security bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConstructionRefusesAShortKeyAndOutOfBoundLifetimes(): void
    {
        $fixture = $this->fixture();
        $build = static fn (string $key, DateInterval $lifetime, int $idle): DoctrinePortalSessionStore =>
            new DoctrinePortalSessionStore(
                $fixture->database,
                $fixture->tables,
                $fixture->loader,
                $fixture->clock,
                $fixture->ownership,
                new ImmediateTransactionManager(),
                $key,
                $lifetime,
                $idle,
            );

        foreach (
            [
            'a 31-byte binding key' => [str_repeat('k', 31), new DateInterval('PT8H'), 1_800],
            'a four-minute lifetime' => [str_repeat('k', 32), new DateInterval('PT4M'), 1_800],
            'an eight-day lifetime' => [str_repeat('k', 32), new DateInterval('P8D'), 1_800],
            'a 59-second idle limit' => [str_repeat('k', 32), new DateInterval('PT8H'), 59],
            'a 24-hour-and-one-second idle limit' => [str_repeat('k', 32), new DateInterval('PT8H'), 86_401],
            ] as $case => [$key, $lifetime, $idle]
        ) {
            try {
                $build($key, $lifetime, $idle);
                self::fail(sprintf('The store must refuse %s.', $case));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Assert that rotating the fixture's own session with an otherwise valid intent is refused.
     *
     * @param   PortalSessionFixture  $fixture  Store, table and identity double under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function expectRotationRefused(PortalSessionFixture $fixture): void
    {
        try {
            $fixture->store->rotate($fixture->intent(), $fixture->now);
            self::fail('Rotation must be refused.');
        } catch (StepUpRejected) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Build a store over a fresh SQLite table holding one live session for the test member.
     *
     * @return  PortalSessionFixture  Store, connection, doubles and the created session.
     *
     * @since   2.0.0
     */
    private function fixture(): PortalSessionFixture
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $table = $schema->createTable('kumwe_portal_sessions');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('token_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('workspace_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('membership_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('membership_version', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('policy_generation', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('security_epoch', Types::BIGINT);
        $table->addColumn('csrf_token', Types::STRING, ['length' => 128]);
        $table->addColumn('user_agent_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('authenticated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('step_up_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addUniqueIndex(['id']);
        $table->addUniqueIndex(['token_digest']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }

        return new PortalSessionFixture($database, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }
}

/**
 * One live portal session in a fresh table, with the doubles the store depends on exposed for steering.
 *
 * @since  2.0.0
 */
final class PortalSessionFixture
{
    /**
     * Installation table-name mapper over the test prefix.
     *
     * @var    TableNames
     * @since  2.0.0
     */
    public readonly TableNames $tables;

    /**
     * Steerable live-identity loader.
     *
     * @var    ScriptedPortalIdentityLoader
     * @since  2.0.0
     */
    public readonly ScriptedPortalIdentityLoader $loader;

    /**
     * Recording site-ownership writer.
     *
     * @var    RecordingOwnershipWriter
     * @since  2.0.0
     */
    public readonly RecordingOwnershipWriter $ownership;

    /**
     * Fixed clock the store reads.
     *
     * @var    ClockInterface
     * @since  2.0.0
     */
    public readonly ClockInterface $clock;

    /**
     * Store under test.
     *
     * @var    DoctrinePortalSessionStore
     * @since  2.0.0
     */
    public readonly DoctrinePortalSessionStore $store;

    /**
     * Principal the live session was issued to.
     *
     * @var    AuthenticatedPrincipal
     * @since  2.0.0
     */
    public readonly AuthenticatedPrincipal $principal;

    /**
     * The session the fixture created, as the store disclosed it.
     *
     * @var    PortalSession
     * @since  2.0.0
     */
    public readonly PortalSession $session;

    /**
     * One-time cookie token of the created session.
     *
     * @var    string
     * @since  2.0.0
     */
    public readonly string $cookieToken;

    /**
     * Create the table's one live session bound to the test browser at the fixed instant.
     *
     * @param  Connection         $database  SQLite connection holding the fresh table.
     * @param  DateTimeImmutable  $now       Instant the clock answers.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly Connection $database, public readonly DateTimeImmutable $now)
    {
        $this->tables = new TableNames($database, 'kumwe_');
        $this->principal = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            DoctrinePortalSessionStoreTest::USER,
            ['portal.access'],
        );
        $this->loader = new ScriptedPortalIdentityLoader($this->identity());
        $this->ownership = new RecordingOwnershipWriter();
        $this->clock = new class ($now) implements ClockInterface {
            /**
             * Bind the clock to one instant.
             *
             * @param  DateTimeImmutable  $now  Instant every read answers.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            /**
             * Answer the fixed instant.
             *
             * @return  DateTimeImmutable  The bound instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $this->store = new DoctrinePortalSessionStore(
            $database,
            $this->tables,
            $this->loader,
            $this->clock,
            $this->ownership,
            new ImmediateTransactionManager(),
            str_repeat('k', 32),
            new DateInterval('PT8H'),
            1_800,
        );
        $created = $this->store->create(
            new PortalPasswordIdentity($this->principal, 1),
            new PortalContext(SiteContext::default(), $this->membership()),
            DoctrinePortalSessionStoreTest::USER_AGENT,
        );
        $this->session = $created->session;
        $this->cookieToken = $created->cookieToken;
        $this->ownership->recorded = [];
    }

    /**
     * Build the membership the stored session names, optionally drifted on one coordinate.
     *
     * @param   string  $drift  Coordinate to move, or `none`.
     *
     * @return  ?MembershipContext  The membership, or null when it is withdrawn.
     *
     * @since   2.0.0
     */
    public function membership(string $drift = 'none'): ?MembershipContext
    {
        return match ($drift) {
            'no_membership' => null,
            default => AuthorizationContext::membership(
                $drift === 'organization' ? 'globex' : 'acme',
                $drift === 'workspace' ? 'south' : 'north',
                $drift === 'membership_version' ? 2 : 1,
                $drift === 'policy_generation' ? 2 : 1,
                $drift === 'membership_id'
                    ? '018f22e2-7c8b-7ab0-8f3a-88e8026bb513'
                    : DoctrinePortalSessionStoreTest::MEMBERSHIP,
            ),
        };
    }

    /**
     * Build the live identity the loader answers with, optionally drifted on one coordinate.
     *
     * @param   string  $drift  Coordinate to move, or `none` for the identity the row was stored under.
     *
     * @return  PortalSessionIdentity  Identity for the loader to answer.
     *
     * @since   2.0.0
     */
    public function identity(string $drift = 'none'): PortalSessionIdentity
    {
        $epoch = $drift === 'security_epoch' ? 2 : 1;
        $principal = $epoch === 1
            ? $this->principal
            : AuthenticatedPrincipal::issueFromStrings(
                AuthorizationContext::provenance(),
                DoctrinePortalSessionStoreTest::USER,
                ['portal.access'],
                null,
                $epoch,
            );

        return new PortalSessionIdentity(
            $principal,
            new PortalContext(SiteContext::default(), $this->membership($drift)),
            $epoch,
        );
    }

    /**
     * Build a step-up intent for the fixture session, overriding any coordinate the caller names.
     *
     * @param   ?string  $subjectId               Member the intent claims, defaulting to the session's owner.
     * @param   ?string  $sessionId               Session the intent names, defaulting to the fixture session.
     * @param   ?string  $siteIdentifier          Site the intent was bound to.
     * @param   ?string  $organizationIdentifier  Organization the intent was bound to; null withdraws it.
     * @param   ?string  $workspaceIdentifier     Workspace the intent was bound to; null withdraws it.
     * @param   ?int     $securityEpoch           Epoch the intent was bound to.
     *
     * @return  StepUpIntent  Intent to rotate with.
     *
     * @since   2.0.0
     */
    public function intent(
        ?string $subjectId = null,
        ?string $sessionId = null,
        ?string $siteIdentifier = null,
        ?string $organizationIdentifier = 'acme',
        ?string $workspaceIdentifier = 'north',
        ?int $securityEpoch = null,
    ): StepUpIntent {
        return new StepUpIntent(
            $subjectId ?? DoctrinePortalSessionStoreTest::USER,
            $sessionId ?? $this->session->id,
            $siteIdentifier ?? SiteContext::DEFAULT,
            $organizationIdentifier,
            $workspaceIdentifier,
            'records.approve',
            $securityEpoch ?? 1,
        );
    }

    /**
     * Move one timestamp column of the fixture session relative to the fixed instant.
     *
     * @param   string  $column    `last_seen_at` or `expires_at`.
     * @param   string  $modifier  Relative modifier such as `-1 second`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function stamp(string $column, string $modifier): void
    {
        $this->database->update(
            $this->tables->raw('portal_sessions'),
            [$column => $this->now->modify($modifier)],
            ['id' => $this->session->id],
            [$column => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Read the fixture session's stored inactivity instant.
     *
     * @return  string  The instant in RFC 3339 form.
     *
     * @since   2.0.0
     */
    public function lastSeenAt(): string
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT last_seen_at FROM %s WHERE id = ?',
            $this->tables->quoted('portal_sessions'),
        ), [$this->session->id]);
        TestCase::assertIsString($value);

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /**
     * Count the rows the table holds.
     *
     * @return  int  Row count.
     *
     * @since   2.0.0
     */
    public function rows(): int
    {
        $count = $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('portal_sessions'),
        ));
        TestCase::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * Read the identifier of the table's only row.
     *
     * @return  string  Session UUID.
     *
     * @since   2.0.0
     */
    public function onlyRowId(): string
    {
        $id = $this->database->fetchOne(sprintf('SELECT id FROM %s', $this->tables->quoted('portal_sessions')));
        TestCase::assertIsString($id);

        return $id;
    }
}

/**
 * Identity loader answering a scripted identity and recording the coordinates it was asked about.
 *
 * @since  2.0.0
 */
final class ScriptedPortalIdentityLoader implements PortalSessionIdentityLoader
{
    /**
     * Every set of coordinates the store asked about, in call order.
     *
     * @var    list<array{string, string, ?string, ?string, ?string, string}>
     * @since  2.0.0
     */
    public array $calls = [];

    /**
     * Side effect to run inside a load, simulating a concurrent writer.
     *
     * @var    ?\Closure
     * @since  2.0.0
     */
    public ?\Closure $sideEffect = null;

    /**
     * Bind the loader to the identity it answers.
     *
     * @param  ?PortalSessionIdentity  $identity  Identity to answer, or null to withdraw it.
     *
     * @since  2.0.0
     */
    public function __construct(public ?PortalSessionIdentity $identity)
    {
    }

    /**
     * Record the coordinates and answer the scripted identity.
     *
     * @param   string   $subjectId               User UUID stored with the session.
     * @param   string   $siteIdentifier          Stored server-resolved site.
     * @param   ?string  $organizationIdentifier  Stored organization selection, or null.
     * @param   ?string  $membershipId            Stored membership UUID, or null.
     * @param   ?string  $workspaceIdentifier     Stored workspace selection, or null.
     * @param   string   $sessionId               Stored session UUID.
     *
     * @return  ?PortalSessionIdentity  The scripted identity.
     *
     * @since   2.0.0
     */
    public function load(
        string $subjectId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        ?string $membershipId,
        ?string $workspaceIdentifier,
        string $sessionId,
    ): ?PortalSessionIdentity {
        $this->calls[] = [
            $subjectId,
            $siteIdentifier,
            $organizationIdentifier,
            $membershipId,
            $workspaceIdentifier,
            $sessionId,
        ];
        if ($this->sideEffect instanceof \Closure) {
            ($this->sideEffect)();
        }

        return $this->identity;
    }
}

/**
 * Ownership writer that records every resource it was asked to record or remove.
 *
 * @since  2.0.0
 */
final class RecordingOwnershipWriter implements ResourceSiteOwnershipWriter
{
    /**
     * Session identifiers and sites recorded, in call order.
     *
     * @var    list<array{string, string}>
     * @since  2.0.0
     */
    public array $recorded = [];

    /**
     * Session identifiers and sites removed, in call order.
     *
     * @var    list<array{string, string}>
     * @since  2.0.0
     */
    public array $removed = [];

    /**
     * Record a portal-session ownership row.
     *
     * @param   AuthorizationResource  $resource  Portal session resource.
     * @param   SiteContext            $site      Owning site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void
    {
        TestCase::assertSame('portal_session', $resource->type());
        $this->recorded[] = [$resource->identifier(), $site->identifier()];
    }

    /**
     * Remove a portal-session ownership row.
     *
     * @param   AuthorizationResource  $resource      Portal session resource.
     * @param   SiteContext            $expectedSite  Site the row must belong to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
    {
        TestCase::assertSame('portal_session', $resource->type());
        $this->removed[] = [$resource->identifier(), $expectedSite->identifier()];
    }

    /**
     * Reassignment is never used by the session store.
     *
     * @param   ResourceOwnership  $owner     Ownership to move.
     * @param   OwnershipScope     $expected  Scope it must currently hold.
     *
     * @return  void
     *
     * @throws  \LogicException  Always; the store never reassigns.
     *
     * @since   2.0.0
     */
    public function reassign(ResourceOwnership $owner, OwnershipScope $expected): void
    {
        throw new \LogicException('The portal session store never reassigns ownership.');
    }
}
