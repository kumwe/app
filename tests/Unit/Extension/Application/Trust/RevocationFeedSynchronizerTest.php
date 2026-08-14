<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Application\Trust;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedState;
use Kumwe\CMS\Extension\Application\Trust\RevocationList;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumRevocationListVerifier;
use Kumwe\CMS\Kernel\Configuration\RevocationFeedConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RevocationList::class)]
#[CoversClass(RevocationFeedState::class)]
#[CoversClass(RevocationFeedConfiguration::class)]
#[CoversClass(SodiumRevocationListVerifier::class)]
/**
 * Exercises the revocation-list format, its pinned-key verification, and the staleness decision.
 *
 * Key material is derived from a readable seed rather than generated randomly, so the fixtures carry no
 * entropy for the repository's own secret scanner to flag while still being real Ed25519 key pairs.
 *
 * @since  2.0.0
 */
final class RevocationFeedSynchronizerTest extends TestCase
{
    /**
     * Readable seed the fixture signing key pair is derived from.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SEED_STEM = 'kumwe-revocation-feed-test-seed-';

    /**
     * A well-formed envelope parses into the statement it carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWellFormedEnvelopeParsesIntoItsStatement(): void
    {
        $list = RevocationList::fromEnvelope($this->envelope());

        self::assertSame('kumwe-security', $list->issuer);
        self::assertSame(7, $list->sequence);
        self::assertSame(['vendor.release.2026'], $list->revokedKeyIds());
        self::assertSame('The publisher reported the key lost.', $list->reasonFor('vendor.release.2026'));
        self::assertNull($list->reasonFor('vendor.release.2027'));
        self::assertTrue($list->isCurrentAt(new DateTimeImmutable('2026-08-14T00:00:00+00:00')));
        self::assertFalse($list->isCurrentAt(new DateTimeImmutable('2026-09-14T00:00:00+00:00')));
    }

    /**
     * A statement signed by the pinned key verifies, and the same bytes under another key do not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyThePinnedKeyVerifiesAStatement(): void
    {
        $list = RevocationList::fromEnvelope($this->envelope());
        $verifier = new SodiumRevocationListVerifier();
        $other = sodium_crypto_sign_seed_keypair(str_pad('kumwe-other-issuer-seed-', 32, '.'));

        self::assertTrue($verifier->verify($this->publicKey(), $list->signedBytes, $list->signatureBase64));
        self::assertFalse($verifier->verify(
            base64_encode(sodium_crypto_sign_publickey($other)),
            $list->signedBytes,
            $list->signatureBase64,
        ));
        self::assertFalse($verifier->verify('not-base64-key-material', $list->signedBytes, $list->signatureBase64));
    }

    /**
     * A statement whose bytes were edited after signing no longer verifies.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditedStatementBytesNoLongerVerify(): void
    {
        $list = RevocationList::fromEnvelope($this->envelope());
        $tampered = str_replace('"sequence":7', '"sequence":8', $list->signedBytes);

        self::assertNotSame($list->signedBytes, $tampered);
        self::assertFalse((new SodiumRevocationListVerifier())->verify(
            $this->publicKey(),
            $tampered,
            $list->signatureBase64,
        ));
    }

    /**
     * An envelope carrying an unknown key, or a malformed field, is refused rather than tolerated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownEnvelopeKeysAreRefused(): void
    {
        $envelope = json_decode($this->envelope(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        $envelope['extra'] = 'unexpected';

        $this->expectException(InvalidArgumentException::class);
        RevocationList::fromEnvelope(json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    /**
     * A statement naming the same key twice is refused, because a duplicate hides a disagreement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateRevocationEntriesAreRefused(): void
    {
        $statement = [
            'format' => RevocationList::FORMAT,
            'issuer' => 'kumwe-security',
            'sequence' => 1,
            'issued_at' => '2026-08-01T00:00:00+00:00',
            'valid_until' => '2026-08-31T00:00:00+00:00',
            'revoked_keys' => [
                ['key_id' => 'vendor.release.2026', 'reason' => 'first'],
                ['key_id' => 'vendor.release.2026', 'reason' => 'second'],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        RevocationList::fromEnvelope($this->wrap(json_encode($statement, JSON_THROW_ON_ERROR)));
    }

    /**
     * A configured feed with no confirmed fetch reads as stale, and one inside its budget does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStalenessIsJudgedAgainstTheLastConfirmedFetch(): void
    {
        $now = new DateTimeImmutable('2026-08-14T12:00:00+00:00');
        $never = new RevocationFeedState('/srv/feed.json', null, 0, null, 0, null, null, null, null, 0, 3_600);
        $fresh = new RevocationFeedState(
            '/srv/feed.json',
            'kumwe-security',
            7,
            str_repeat('a', 64),
            1,
            $now->modify('-10 minutes'),
            $now->modify('-10 minutes'),
            null,
            null,
            0,
            3_600,
        );
        $stale = new RevocationFeedState(
            '/srv/feed.json',
            'kumwe-security',
            7,
            str_repeat('a', 64),
            1,
            $now->modify('-10 days'),
            $now,
            $now,
            'unreachable: the origin could not be reached',
            9,
            3_600,
        );

        self::assertTrue($never->isStale($now));
        self::assertFalse($fresh->isStale($now));
        self::assertTrue($stale->isStale($now));
        self::assertFalse(RevocationFeedState::unconfigured()->isStale($now));
        self::assertFalse(RevocationFeedState::unconfigured()->toArray($now)['configured']);
        self::assertTrue($stale->toArray($now)['stale']);
        self::assertSame(9, $stale->toArray($now)['consecutive_failures']);
    }

    /**
     * A feed origin without its pinned key, or with a plaintext scheme, is a configuration error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFeedConfigurationRefusesAnUnpinnedOrPlaintextOrigin(): void
    {
        self::assertFalse((new RevocationFeedConfiguration())->isEnabled());
        self::assertTrue(
            (new RevocationFeedConfiguration('https://feed.example.test/list.json', $this->publicKey()))->isEnabled(),
        );

        $this->expectException(InvalidArgumentException::class);
        new RevocationFeedConfiguration('https://feed.example.test/list.json');
    }

    /**
     * A plaintext origin is refused before any fetch can be attempted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlaintextFeedOriginIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevocationFeedConfiguration('http://feed.example.test/list.json', $this->publicKey());
    }

    /**
     * Build a signed envelope carrying the standard fixture statement.
     *
     * @return  string  Envelope JSON as an origin would serve it.
     *
     * @since   2.0.0
     */
    private function envelope(): string
    {
        $statement = json_encode([
            'format' => RevocationList::FORMAT,
            'issuer' => 'kumwe-security',
            'sequence' => 7,
            'issued_at' => '2026-08-01T00:00:00+00:00',
            'valid_until' => '2026-08-31T00:00:00+00:00',
            'revoked_keys' => [
                ['key_id' => 'vendor.release.2026', 'reason' => 'The publisher reported the key lost.'],
            ],
        ], JSON_THROW_ON_ERROR);

        return $this->wrap($statement);
    }

    /**
     * Wrap one statement in a correctly signed envelope.
     *
     * @param   string  $statement  Statement text the signature must cover.
     *
     * @return  string  Envelope JSON.
     *
     * @since   2.0.0
     */
    private function wrap(string $statement): string
    {
        $keyPair = sodium_crypto_sign_seed_keypair($this->seed());

        return json_encode([
            'format' => RevocationList::ENVELOPE_FORMAT,
            'algorithm' => 'ed25519',
            'key_id' => 'kumwe-security-2026',
            'document' => $statement,
            'signature' => base64_encode(
                sodium_crypto_sign_detached($statement, sodium_crypto_sign_secretkey($keyPair)),
            ),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Return the fixture issuer's public key in the form configuration pins it as.
     *
     * @return  string  Standard base64 of the 32-byte Ed25519 public key.
     *
     * @since   2.0.0
     */
    private function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed())));
    }

    /**
     * Derive the fixture key seed from readable text rather than from random bytes.
     *
     * @return  string  Exactly 32 bytes of patterned seed material.
     *
     * @since   2.0.0
     */
    private function seed(): string
    {
        return substr(str_pad(self::SEED_STEM, 32, '.'), 0, 32);
    }
}
