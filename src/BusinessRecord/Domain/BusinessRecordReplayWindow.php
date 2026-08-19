<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How long a command's idempotency claim replays, and how long it is remembered after that.
 *
 * The caller mints the operation identifier, so the platform's half of the bargain is saying for how long
 * that identifier means something. Two horizons say it, and they are deliberately different:
 *
 * - **The replay horizon.** Inside it a repeat of the command hands back the outcome the first attempt
 *   recorded, so the command has exactly one effect however many times it is submitted.
 * - **The retention horizon.** Between the replay horizon and this one the claim is still in the ledger
 *   but no longer replays, so a late arrival is *refused by name* rather than silently becoming a second
 *   effect. Refusal is the whole point: a duplicate that is announced can be reconciled, and one that is
 *   not becomes a second document nobody knows about.
 *
 * A terminal that captured work offline and reconnects days later is the case this exists for. Decision
 * D14 does not build point of sale, but it does require that a long disconnection is a configuration
 * question rather than an architectural impossibility — so the window is declared, bounded and
 * configurable rather than a fixed day compiled into a mutation path.
 *
 * The retention horizon is the instant a claim becomes collectable, which is what the ledger stores as
 * the entry's expiry; the replay horizon is derived from the instant the claim was taken and is never
 * stored, so widening or narrowing the replay window changes how existing claims behave without
 * rewriting a single row.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordReplayWindow
{
    /**
     * Shortest replay horizon an installation may declare, being one hour.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MINIMUM_REPLAY_SECONDS = 3_600;

    /**
     * Replay horizon an installation gets without configuring one, being seven days.
     *
     * A week covers a terminal that loses connectivity over a weekend and a long public holiday, which
     * is the disconnection an operator can plan for. It is deliberately longer than the day this
     * replaced, because a claim that stops replaying is a claim that stops protecting.
     *
     * @var    int
     * @since  2.0.0
     */
    public const DEFAULT_REPLAY_SECONDS = 604_800;

    /**
     * Longest replay horizon an installation may declare, being ninety days.
     *
     * The ceiling is stated rather than left open because every claim inside the horizon is a row the
     * ledger carries and a purge cannot reclaim, and an unbounded horizon turns a correctness guarantee
     * into an operational one nobody sized.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_REPLAY_SECONDS = 7_776_000;

    /**
     * Retention horizon an installation gets without configuring one, being thirty days.
     *
     * @var    int
     * @since  2.0.0
     */
    public const DEFAULT_RETENTION_SECONDS = 2_592_000;

    /**
     * Longest retention horizon an installation may declare, being one year.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_RETENTION_SECONDS = 31_536_000;

    /**
     * Declare the two horizons and prove they can hold together.
     *
     * @param   int  $replaySeconds     How long a completed claim hands its outcome back to a repeat.
     * @param   int  $retentionSeconds  How long the claim stays in the ledger refusing a late arrival;
     *          never shorter than the replay horizon, because a claim that stopped replaying has to
     *          survive long enough to say so.
     *
     * @throws  InvalidArgumentException  When either horizon falls outside its declared bounds, or when
     *          retention would end before replay does.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $replaySeconds = self::DEFAULT_REPLAY_SECONDS,
        public int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
    ) {
        if ($replaySeconds < self::MINIMUM_REPLAY_SECONDS || $replaySeconds > self::MAXIMUM_REPLAY_SECONDS) {
            throw new InvalidArgumentException(
                'The idempotency replay window must be between one hour and ninety days.',
            );
        }
        if ($retentionSeconds > self::MAXIMUM_RETENTION_SECONDS) {
            throw new InvalidArgumentException('The idempotency retention window must not exceed one year.');
        }
        if ($retentionSeconds < $replaySeconds) {
            throw new InvalidArgumentException(
                'The idempotency retention window must outlast the replay window it protects.',
            );
        }
    }

    /**
     * Read the two horizons from operator configuration, falling back to the declared defaults.
     *
     * Configuration is text because it arrives from the environment, and a value that is absent, empty or
     * not a whole number of seconds is treated as unset rather than as zero — a misspelled setting must
     * not silently shorten a correctness window to nothing.
     *
     * @param   ?string  $replaySeconds     Configured replay horizon in seconds, or null when unset.
     * @param   ?string  $retentionSeconds  Configured retention horizon in seconds, or null when unset.
     *
     * @return  self  The declared window.
     *
     * @throws  InvalidArgumentException  When a configured value is present but outside its bounds.
     *
     * @since   2.0.0
     */
    public static function fromConfiguration(?string $replaySeconds, ?string $retentionSeconds): self
    {
        return new self(
            self::seconds($replaySeconds, self::DEFAULT_REPLAY_SECONDS),
            self::seconds($retentionSeconds, self::DEFAULT_RETENTION_SECONDS),
        );
    }

    /**
     * Say when a claim taken now stops being remembered and becomes collectable.
     *
     * @param   DateTimeImmutable  $claimedAt  Instant the claim was taken, from the service's own clock.
     *
     * @return  DateTimeImmutable  The retention horizon, which the ledger stores as the entry's expiry.
     *
     * @since   2.0.0
     */
    public function expiryFrom(DateTimeImmutable $claimedAt): DateTimeImmutable
    {
        return $claimedAt->add(new DateInterval('PT' . $this->retentionSeconds . 'S'));
    }

    /**
     * Decide whether a repeat presented now is still inside the horizon that replays an outcome.
     *
     * @param   DateTimeImmutable  $claimedAt  Instant the original claim was taken.
     * @param   DateTimeImmutable  $now        Instant the repeat is being judged at.
     *
     * @return  bool  True while the outcome may be handed back; false once the repeat must be refused.
     *
     * @since   2.0.0
     */
    public function admitsReplay(DateTimeImmutable $claimedAt, DateTimeImmutable $now): bool
    {
        return $now < $claimedAt->add(new DateInterval('PT' . $this->replaySeconds . 'S'));
    }

    /**
     * Read one configured horizon, refusing anything that is not a plain count of seconds.
     *
     * @param   ?string  $configured  Raw configured value.
     * @param   int      $fallback    Declared default used when nothing is configured.
     *
     * @return  int  The configured seconds, or the fallback.
     *
     * @throws  InvalidArgumentException  When the value is present but is not a positive whole number.
     *
     * @since   2.0.0
     */
    private static function seconds(?string $configured, int $fallback): int
    {
        $value = $configured === null ? '' : trim($configured);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) {
            throw new InvalidArgumentException('An idempotency window must be configured as whole seconds.');
        }

        return (int) $value;
    }
}
