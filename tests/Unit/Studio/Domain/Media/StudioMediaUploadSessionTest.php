<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Media;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Media\StudioMediaAcceptedAsset;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPlan;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the persisted upload aggregate accepts only canonical forward transitions and completion claims.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaUploadSession::class)]
#[CoversClass(StudioMediaAcceptedAsset::class)]
#[CoversClass(StudioMediaUploadPlan::class)]
#[CoversClass(StudioMediaUploadRequest::class)]
#[CoversClass(StudioMediaUploadState::class)]
final class StudioMediaUploadSessionTest extends TestCase
{
    /**
     * A transfer advances through verifying and complete while preserving immutable scope and progress.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCanonicalCompletionAdvancesUnderAnExclusiveVersionClaim(): void
    {
        $authorized = self::session();
        $verifying = $authorized->transition(StudioMediaUploadState::Verifying, 128);
        $claimed = $verifying->claimCompletion();
        $complete = $claimed->transition(
            StudioMediaUploadState::Complete,
            128,
            new StudioMediaAcceptedAsset('assets/one', 'sha256-one', 'ready'),
        );

        self::assertSame(StudioMediaUploadState::Complete, $complete->state);
        self::assertSame(4, $complete->version);
        self::assertSame('contexts/one', $complete->contextKey);
        self::assertSame(128, $complete->transferred);
    }

    /**
     * Terminal state and backwards progress cannot be overwritten by a late concurrent request.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBackwardsAndTerminalTransitionsAreRefused(): void
    {
        $verifying = self::session()->transition(StudioMediaUploadState::Verifying, 128);
        $failed = $verifying->transition(
            StudioMediaUploadState::Failed,
            128,
            failureCode: 'studio.media/upload-failed',
        );

        $this->expectException(InvalidArgumentException::class);
        $failed->transition(StudioMediaUploadState::Complete, 128, new StudioMediaAcceptedAsset(
            'assets/late',
            'sha256-late',
            'ready',
        ));
    }

    /**
     * Reject every malformed upload request, plan and accepted-asset coordinate at construction time.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedMediaValueObjectsFailClosed(): void
    {
        $cases = [
            'asset identity' => static fn () => new StudioMediaAcceptedAsset('__proto__', 'r1', 'ready'),
            'asset revision' => static fn () => new StudioMediaAcceptedAsset('assets/one', '', 'ready'),
            'asset state' => static fn () => new StudioMediaAcceptedAsset('assets/one', 'r1', 'unknown'),
            'plan maximum' => static fn () => new StudioMediaUploadPlan(0, false),
            'plan chunk' => static fn () => new StudioMediaUploadPlan(1024, true, 1),
            'request filename' => static fn () => new StudioMediaUploadRequest(
                '../one.png',
                'image/png',
                128,
                'studio.media/content',
            ),
            'request media type' => static fn () => new StudioMediaUploadRequest(
                'one.png',
                'IMAGE/PNG',
                128,
                'studio.media/content',
            ),
            'request size' => static fn () => new StudioMediaUploadRequest(
                'one.png',
                'image/png',
                0,
                'studio.media/content',
            ),
            'request purpose' => static fn () => new StudioMediaUploadRequest(
                'one.png',
                'image/png',
                128,
                'content',
            ),
            'request checksum' => static fn () => new StudioMediaUploadRequest(
                'one.png',
                'image/png',
                128,
                'studio.media/content',
                'sha256-invalid',
            ),
            'request document type' => static fn () => StudioMediaUploadRequest::fromDocument([]),
            'request document shape' => static fn () => StudioMediaUploadRequest::fromDocument((object) [
                'filename' => 'one.png',
            ]),
            'request document values' => static fn () => StudioMediaUploadRequest::fromDocument((object) [
                'byteSize' => '128',
                'filename' => 'one.png',
                'mediaType' => 'image/png',
                'purpose' => 'studio.media/content',
            ]),
        ];

        foreach ($cases as $case => $operation) {
            self::assertInvalid($operation, $case);
        }
    }

    /**
     * Reject persisted snapshots whose authority, progress or terminal-state evidence is inconsistent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedUploadSnapshotsAndCompletionClaimsFailClosed(): void
    {
        $asset = new StudioMediaAcceptedAsset('assets/one', 'r1', 'ready');
        $cases = [
            'upload identity' => static fn () => self::session(id: '__proto__'),
            'scope coordinate' => static fn () => self::session(actorId: ''),
            'context identity' => static fn () => self::session(contextKey: 'bad context'),
            'token digest' => static fn () => self::session(tokenDigest: 'invalid'),
            'negative progress' => static fn () => self::session(transferred: -1),
            'missing completed asset' => static fn () => self::session(state: StudioMediaUploadState::Complete),
            'unexpected asset' => static fn () => self::session(asset: $asset),
            'missing failure code' => static fn () => self::session(state: StudioMediaUploadState::Failed),
            'unexpected failure code' => static fn () => self::session(failureCode: 'studio.media/upload-failed'),
            'invalid persistence version' => static fn () => self::session(version: 0),
            'premature completion claim' => static fn () => self::session()->claimCompletion(),
        ];

        foreach ($cases as $case => $operation) {
            self::assertInvalid($operation, $case);
        }
    }

    /**
     * Assert one domain operation rejects malformed state without retaining its value in the failure.
     *
     * @param   callable  $operation  Invalid construction or transition.
     * @param   string    $case       Scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertInvalid(callable $operation, string $case): void
    {
        try {
            $operation();
            self::fail('The invalid Studio media value was accepted: ' . $case);
        } catch (InvalidArgumentException $failure) {
            self::assertNotSame('', $failure->getMessage(), $case);
        }
    }

    /**
     * Build a valid deterministic authorized upload snapshot.
     *
     * @param   string                    $id           Upload identifier.
     * @param   string                    $actorId      Authorized actor identifier.
     * @param   string                    $siteId       Owning site identifier.
     * @param   string                    $contextKey   Host-session context key.
     * @param   string                    $generation   Host-session generation.
     * @param   StudioMediaUploadState    $state        Current upload lifecycle state.
     * @param   int                       $transferred  Bytes accepted so far.
     * @param   string                    $tokenDigest  SHA-256 grant-token digest.
     * @param   StudioMediaAcceptedAsset|null  $asset   Accepted asset after completion.
     * @param   string|null               $failureCode  Stable terminal failure code.
     * @param   int                       $version      Optimistic aggregate version.
     *
     * @return  StudioMediaUploadSession  Authorized test aggregate.
     *
     * @since  2.0.0
     */
    private static function session(
        string $id = 'uploads/0123456789abcdef0123456789abcdef',
        string $actorId = 'actors/one',
        string $siteId = 'default',
        string $contextKey = 'contexts/one',
        string $generation = 'session-r1',
        StudioMediaUploadState $state = StudioMediaUploadState::Authorized,
        int $transferred = 0,
        string $tokenDigest = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ?StudioMediaAcceptedAsset $asset = null,
        ?string $failureCode = null,
        int $version = 1,
    ): StudioMediaUploadSession {
        return new StudioMediaUploadSession(
            $id,
            $actorId,
            $siteId,
            $contextKey,
            $generation,
            new StudioMediaUploadRequest('one.png', 'image/png', 128, 'studio.media/content'),
            new StudioMediaUploadPlan(1024, false),
            $state,
            $transferred,
            $tokenDigest,
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
            $asset,
            $failureCode,
            $version,
        );
    }
}
