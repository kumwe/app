<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Host;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Host\StudioMutationReplayRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioMutationReplayRecord::class)]
/**
 * Proves the replay record admits only its exact digest grammars and outcome envelope version.
 *
 * @since  2.0.0
 */
final class StudioMutationReplayRecordTest extends TestCase
{
    /**
     * Prove a claim with proved coordinates and a versioned protected outcome is retained verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidCoordinatesAndOutcomeAreRetained(): void
    {
        $scope = hash('sha256', 'scope');
        $intent = 'sha256-' . base64_encode(hash('sha256', 'intent', true));
        $record = new StudioMutationReplayRecord($scope, $intent, 'v1.outcome');

        self::assertSame($scope, $record->scopeDigest);
        self::assertSame($intent, $record->intentDigest);
        self::assertSame('v1.outcome', $record->protectedOutcome);
        self::assertNull((new StudioMutationReplayRecord($scope, $intent, null))->protectedOutcome);
    }

    /**
     * Prove a scope digest outside lowercase SHA-256 hex is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignScopeDigestIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scope digest');

        new StudioMutationReplayRecord(
            strtoupper(hash('sha256', 'scope')),
            'sha256-' . base64_encode(hash('sha256', 'intent', true)),
            null,
        );
    }

    /**
     * Prove an intent digest outside Producer's canonical SRI grammar is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignIntentDigestIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('intent digest');

        new StudioMutationReplayRecord(hash('sha256', 'scope'), 'sha256-not-an-sri-digest', null);
    }

    /**
     * Prove a protected outcome outside the supported envelope version is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedOutcomeEnvelopeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('envelope');

        new StudioMutationReplayRecord(
            hash('sha256', 'scope'),
            'sha256-' . base64_encode(hash('sha256', 'intent', true)),
            'v2.outcome',
        );
    }
}
