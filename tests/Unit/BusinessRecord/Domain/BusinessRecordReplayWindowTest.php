<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordReplayWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordReplayWindow::class)]
#[CoversClass(BusinessRecordIdempotencyConflict::class)]
/**
 * Pins that a caller-minted key replays for a declared, bounded time and is refused by name afterwards.
 *
 * @since  2.0.0
 */
final class BusinessRecordReplayWindowTest extends TestCase
{
    /**
     * The default window is a week of replay behind a month of memory, not the fixed day it replaced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDefaultWindowRemembersAClaimLongerThanItReplaysIt(): void
    {
        $window = new BusinessRecordReplayWindow();
        $claimedAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');

        self::assertSame(604_800, $window->replaySeconds);
        self::assertSame(2_592_000, $window->retentionSeconds);
        self::assertTrue($window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-07T23:59:59+00:00')));
        self::assertFalse($window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-08T00:00:01+00:00')));
        self::assertSame(
            '2026-08-31T00:00:00+00:00',
            $window->expiryFrom($claimedAt)->format('Y-m-d\TH:i:sP'),
        );
        self::assertGreaterThan(
            $claimedAt->modify('+' . $window->replaySeconds . ' seconds'),
            $window->expiryFrom($claimedAt),
            'A claim must outlive its replay window so a late repeat is refused rather than reapplied.',
        );
    }

    /**
     * A terminal reconnecting a week later still replays, which the fixed day it replaced did not allow.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClaimStillReplaysDaysAfterTheDayItUsedToExpireOn(): void
    {
        $window = new BusinessRecordReplayWindow();
        $claimedAt = new DateTimeImmutable('2026-08-01T08:00:00+00:00');

        self::assertTrue(
            $window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-02T08:00:01+00:00')),
            'A reconnect just past twenty-four hours must replay rather than produce a second effect.',
        );
        self::assertTrue($window->admitsReplay($claimedAt, new DateTimeImmutable('2026-08-06T08:00:00+00:00')));
    }

    /**
     * Every bound the contract states is enforced, including that retention outlasts replay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredBoundsAreEnforced(): void
    {
        foreach (
            [
                [BusinessRecordReplayWindow::MINIMUM_REPLAY_SECONDS - 1, 2_592_000],
                [BusinessRecordReplayWindow::MAXIMUM_REPLAY_SECONDS + 1, 31_536_000],
                [3_600, BusinessRecordReplayWindow::MAXIMUM_RETENTION_SECONDS + 1],
                [604_800, 604_799],
            ] as [$replay, $retention]
        ) {
            try {
                new BusinessRecordReplayWindow($replay, $retention);
                self::fail('A window of ' . $replay . '/' . $retention . ' seconds must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('idempotency', $exception->getMessage());
            }
        }

        $edge = new BusinessRecordReplayWindow(
            BusinessRecordReplayWindow::MAXIMUM_REPLAY_SECONDS,
            BusinessRecordReplayWindow::MAXIMUM_RETENTION_SECONDS,
        );
        self::assertSame(7_776_000, $edge->replaySeconds);
    }

    /**
     * An operator configures the window in whole seconds, and a misspelling is refused rather than assumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfigurationIsReadAsWholeSecondsOrRefused(): void
    {
        $configured = BusinessRecordReplayWindow::fromConfiguration('86400', '172800');
        self::assertSame(86_400, $configured->replaySeconds);
        self::assertSame(172_800, $configured->retentionSeconds);

        $unset = BusinessRecordReplayWindow::fromConfiguration(null, '  ');
        self::assertSame(BusinessRecordReplayWindow::DEFAULT_REPLAY_SECONDS, $unset->replaySeconds);
        self::assertSame(BusinessRecordReplayWindow::DEFAULT_RETENTION_SECONDS, $unset->retentionSeconds);

        foreach (['0', 'P1D', '86400s', '-1'] as $malformed) {
            try {
                BusinessRecordReplayWindow::fromConfiguration($malformed, null);
                self::fail('A window configured as "' . $malformed . '" must be refused, not assumed.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('whole seconds', $exception->getMessage());
            }
        }
    }

    /**
     * A repeat arriving after the window is refused under its own stable code, not as a reused key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALateRepeatIsRefusedUnderItsOwnStableCode(): void
    {
        $elapsed = new BusinessRecordIdempotencyConflict('replay_window_elapsed');
        $reused = new BusinessRecordIdempotencyConflict('key_reused');

        self::assertSame('business_record.idempotency_replay_window_elapsed', $elapsed->stableCode());
        self::assertNotSame($reused->stableCode(), $elapsed->stableCode());
        self::assertStringContainsString('refused rather than applied a second time', $elapsed->getMessage());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflict state is unsupported');
        new BusinessRecordIdempotencyConflict('expired');
    }
}
