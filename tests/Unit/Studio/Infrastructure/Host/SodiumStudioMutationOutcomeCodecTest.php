<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Host;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioMutationOutcomeRejected;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Infrastructure\Host\SodiumStudioMutationOutcomeCodec;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\HostResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves exact authenticated replay for both Producer mutation outcome kinds.
 *
 * @since  2.0.0
 */
#[CoversClass(SodiumStudioMutationOutcomeCodec::class)]
#[CoversClass(StudioMutationOutcomeRejected::class)]
final class SodiumStudioMutationOutcomeCodecTest extends TestCase
{
    /**
     * Supply one canonical Producer success and one canonical committed refusal.
     *
     * @return  iterable<string, array{HostResult|HostError}>  Logical mutation outcome under test.
     *
     * @since  2.0.0
     */
    public static function outcomes(): iterable
    {
        yield 'success' => [new HostResult((object) ['accepted' => true], 'revision-2')];
        yield 'committed refusal' => [StudioProducerError::error(
            'validation-failed',
            'studio.media/verification-failed',
        )];
    }

    /**
     * Protection hides plaintext and recovers the exact canonical outcome under the same replay coordinates.
     *
     * @param   HostResult|HostError  $outcome  Canonical logical outcome.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('outcomes')]
    public function testProtectionRoundTripsExactProducerOutcome(HostResult|HostError $outcome): void
    {
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));
        $protected = $codec->protect($outcome, self::scope(), self::intent());

        self::assertStringStartsWith('v1.', $protected);
        self::assertStringNotContainsString('accepted', $protected);
        self::assertStringNotContainsString('verification-failed', $protected);
        $recovered = $codec->recover($protected, self::scope(), self::intent());

        if ($outcome instanceof HostResult) {
            self::assertInstanceOf(HostResult::class, $recovered);
            self::assertEquals($outcome->toDocument(), $recovered->toDocument());
        } else {
            self::assertInstanceOf(HostError::class, $recovered);
            self::assertSame($outcome->toCanonicalJson(), $recovered->toCanonicalJson());
        }
    }

    /**
     * A changed scope, intent, ciphertext or legacy raw result is rejected without interpretation.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testProtectionRejectsChangedBindingTamperingAndLegacyBytes(): void
    {
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));
        $protected = $codec->protect(new HostResult(null), self::scope(), self::intent());
        $candidates = [
            [$protected, hash('sha256', 'other-scope'), self::intent()],
            [$protected, self::scope(), self::digest('other-intent')],
            [substr($protected, 0, -1) . ($protected[-1] === 'A' ? 'B' : 'A'), self::scope(), self::intent()],
            ['{"value":null}', self::scope(), self::intent()],
        ];

        foreach ($candidates as [$candidate, $scope, $intent]) {
            try {
                $codec->recover($candidate, $scope, $intent);
                self::fail('Untrusted replay material must be rejected.');
            } catch (StudioMutationOutcomeRejected) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * The codec refuses a key outside XChaCha20-Poly1305's exact size.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testKeyLengthIsExact(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SodiumStudioMutationOutcomeCodec(str_repeat('k', 31));
    }

    /**
     * Recovery refuses replay coordinates outside their canonical grammar before touching ciphertext.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRecoverRejectsCoordinatesOutsideTheirGrammar(): void
    {
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));
        $protected = $codec->protect(new HostResult(null), self::scope(), self::intent());
        $candidates = [
            [strtoupper(self::scope()), self::intent()],
            [substr(self::scope(), 0, 63), self::intent()],
            [self::scope(), 'sha256-not+valid'],
        ];

        foreach ($candidates as [$scope, $intent]) {
            try {
                $codec->recover($protected, $scope, $intent);
                self::fail('Replay coordinates outside their grammar must be rejected.');
            } catch (StudioMutationOutcomeRejected $rejected) {
                self::assertStringContainsString('binding is invalid', $rejected->getMessage());
            }
        }
    }

    /**
     * Protection refuses to seal an outcome under replay coordinates outside their canonical grammar.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testProtectRejectsCoordinatesOutsideTheirGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('coordinates are invalid');

        (new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32)))
            ->protect(new HostResult(null), 'not-a-digest', self::intent());
    }

    /**
     * A versioned envelope whose Base64url segment is malformed, non-canonical or short is rejected.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUndecodableEnvelopeTextIsRejected(): void
    {
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));
        $candidates = [
            'v1.',
            'v1.!!!',
            'v1.ab',
            'v1.' . rtrim(strtr(base64_encode('short'), '+/', '-_'), '='),
        ];

        foreach ($candidates as $candidate) {
            try {
                $codec->recover($candidate, self::scope(), self::intent());
                self::fail('An undecodable envelope must be rejected.');
            } catch (StudioMutationOutcomeRejected $rejected) {
                self::assertStringContainsString('envelope is invalid', $rejected->getMessage());
            }
        }
    }

    /**
     * Authentically sealed garbage plaintext is rejected as an invalid value after decryption succeeds.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAuthenticGarbagePlaintextIsRejectedAsAnInvalidValue(): void
    {
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));
        $candidates = [
            self::seal('Xgarbage'),
            self::seal('R{"value": 1 }'),
        ];

        foreach ($candidates as $candidate) {
            try {
                $codec->recover($candidate, self::scope(), self::intent());
                self::fail('An authentic envelope carrying garbage plaintext must be rejected.');
            } catch (StudioMutationOutcomeRejected $rejected) {
                self::assertStringContainsString('value is invalid', $rejected->getMessage());
            }
        }
    }

    /**
     * Seal arbitrary plaintext exactly as the codec would, using its test key and coordinates.
     *
     * @param   string  $plaintext  Raw plaintext bytes to authenticate and encrypt.
     *
     * @return  string  Version-one unpadded Base64url envelope accepted by the decrypt stage.
     *
     * @since  2.0.0
     */
    private static function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            "kumwe/studio-mutation-outcome/v1\0" . self::scope() . "\0" . self::intent(),
            $nonce,
            str_repeat('k', 32),
        );

        return 'v1.' . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    /**
     * Return the deterministic App-namespaced scope used by the codec tests.
     *
     * @return  string  Lowercase SHA-256 digest.
     *
     * @since  2.0.0
     */
    private static function scope(): string
    {
        return hash('sha256', 'trusted-app-scope');
    }

    /**
     * Return the deterministic canonical Producer intent digest used by the codec tests.
     *
     * @return  string  Canonical SRI SHA-256 digest.
     *
     * @since  2.0.0
     */
    private static function intent(): string
    {
        return self::digest('producer-intent');
    }

    /**
     * Render one value as Producer's canonical SRI SHA-256 representation.
     *
     * @param   string  $value  Deterministic digest preimage.
     *
     * @return  string  Canonical SRI SHA-256 digest.
     *
     * @since  2.0.0
     */
    private static function digest(string $value): string
    {
        return 'sha256-' . base64_encode(hash('sha256', $value, true));
    }
}
