<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use DateTimeImmutable;
use Kumwe\App\Studio\Application\Media\StudioMediaGrantToken;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPlan;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins deterministic, scope-bound and digest-checked grant derivation.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaGrantToken::class)]
final class StudioMediaGrantTokenTest extends TestCase
{
    /**
     * Recreate one capability exactly while a changed trusted scope derives a different value.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testGrantIsDeterministicAndBoundToEveryTrustedCoordinate(): void
    {
        $grants = new StudioMediaGrantToken(str_repeat('g', 32));
        $session = self::session($grants);

        $restored = $grants->restore($session);
        $differentSite = $grants->derive(
            $session->id,
            $session->actorId,
            'other-site',
            $session->contextKey,
            $session->generation,
            StudioMediaGrantToken::expiry($session),
        );

        self::assertSame($restored, $grants->restore($session));
        self::assertNotSame($restored, $differentSite);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $restored);
    }

    /**
     * Build a session carrying only the digest of its derived token.
     *
     * @param   StudioMediaGrantToken  $grants  Derivation service under test.
     *
     * @return  StudioMediaUploadSession  Authorized immutable session.
     *
     * @since  2.0.0
     */
    private static function session(StudioMediaGrantToken $grants): StudioMediaUploadSession
    {
        $expiry = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $token = $grants->derive(
            'uploads/0123456789abcdef0123456789abcdef',
            'actors:1',
            'default',
            'contexts/grant-1',
            'generation-1',
            $expiry->format('Y-m-d\TH:i:s.u\Z'),
        );

        return new StudioMediaUploadSession(
            'uploads/0123456789abcdef0123456789abcdef',
            'actors:1',
            'default',
            'contexts/grant-1',
            'generation-1',
            new StudioMediaUploadRequest(
                'photo.jpg',
                'image/jpeg',
                10,
                'studio.media/content',
            ),
            new StudioMediaUploadPlan(10, false),
            StudioMediaUploadState::Authorized,
            0,
            hash('sha256', $token),
            $expiry,
        );
    }
}
