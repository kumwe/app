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
     * Build a valid deterministic authorized upload snapshot.
     *
     * @return  StudioMediaUploadSession  Authorized test aggregate.
     *
     * @since  2.0.0
     */
    private static function session(): StudioMediaUploadSession
    {
        return new StudioMediaUploadSession(
            'uploads/0123456789abcdef0123456789abcdef',
            'actors/one',
            'default',
            'contexts/one',
            'session-r1',
            new StudioMediaUploadRequest('one.png', 'image/png', 128, 'studio.media/content'),
            new StudioMediaUploadPlan(1024, false),
            StudioMediaUploadState::Authorized,
            0,
            str_repeat('a', 64),
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        );
    }
}
