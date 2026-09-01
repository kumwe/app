<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Release;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Infrastructure\Release\StudioContextualAuthoringQualification;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves contextual Studio is enabled only by Producer and exact App deployment evidence.
 *
 * @since  2.0.0
 */
#[CoversClass(PinnedStudioContextualAuthoringAvailability::class)]
#[CoversClass(StudioContextualAuthoringQualification::class)]
#[CoversClass(StudioContextualAuthoringReadiness::class)]
#[CoversClass(StudioContextualAuthoringFallbackReason::class)]
final class PinnedStudioContextualAuthoringAvailabilityTest extends TestCase
{
    /**
     * The pinned beta publishes the contextual protocol yet stays unavailable without a browser runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentPinnedBetaStopsClosedAtTheMissingContextualBrowserRuntime(): void
    {
        $root = dirname(__DIR__, 5);
        $release = (string) file_get_contents($root . '/resources/studio-contract/studio-release.json');
        self::assertStringContainsString('"claimedProfiles": []', $release);
        self::assertNotContains('authoring-target', StudioDocumentSchemaRegistry::DOCUMENT_KINDS);
        self::assertTrue(OperationRegistry::isCapability('studio.operation/authoring.resolve-target'));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable, $readiness->reason);
        self::assertSame([
            'available' => false,
            'fallback' => 'structured-form',
            'reason' => 'browser-runtime-unavailable',
        ], $readiness->toArray());
    }

    /**
     * Missing App-owned release evidence cannot fall through to browser or host qualification.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingAppDeploymentEvidenceFailsAtProtocolBoundary(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-studio-missing-' . bin2hex(random_bytes(8));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * A qualification carries only App-owned immutable deployment coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQualificationCarriesExactAppEvidence(): void
    {
        $qualification = new StudioContextualAuthoringQualification(
            '0.1.0-rc.1',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
        );

        self::assertSame('0.1.0-rc.1', $qualification->release);
        self::assertSame(str_repeat('a', 64), $qualification->releaseRecordSha256);
        self::assertSame(str_repeat('b', 64), $qualification->pinRecordSha256);
        self::assertSame(str_repeat('c', 64), $qualification->browserManifestSha256);
        self::assertSame(str_repeat('d', 64), $qualification->browserEntrySha256);
    }

    /**
     * A qualification cannot carry a moving release label or a non-SHA-256 coordinate.
     *
     * @param   string  $release  Candidate exact release.
     * @param   string  $digest   Candidate release-record digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidQualifications')]
    public function testQualificationRequiresExactImmutableCoordinates(string $release, string $digest): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StudioContextualAuthoringQualification(
            $release,
            $digest,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
        );
    }

    /**
     * Supply one invalid semantic release and one invalid digest to the qualification guard.
     *
     * @return  iterable<string, array{string, string}>  Named refusal cases.
     *
     * @since   2.0.0
     */
    public static function invalidQualifications(): iterable
    {
        yield 'moving release label' => ['latest', str_repeat('a', 64)];
        yield 'non SHA-256 digest' => ['0.1.0-test.1', 'sha256-not-hex'];
    }
}
