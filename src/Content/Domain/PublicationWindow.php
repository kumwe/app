<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Optional start and end instants that bound when a published entry is actually delivered.
 *
 * Approval and timing are separate concerns: `ContentStatus::Published` records that an entry has
 * been approved, while this value object records when that approval takes effect. Delivery asks
 * `contains()` with the current instant on every request and the repository stores the two ends as the
 * `publish_at` and `unpublish_at` columns, so scheduling never depends on a job that flips a status at
 * the right minute. Either end may be absent, which is how an entry publishes immediately or forever.
 *
 * @since  2.0.0
 */
final readonly class PublicationWindow
{
    /**
     * Bound the window, rejecting a range that would close before it opens.
     *
     * @param   ?DateTimeImmutable  $startsAt  Instant delivery begins, or null to deliver from publication.
     * @param   ?DateTimeImmutable  $endsAt    Instant delivery stops, or null to deliver indefinitely.
     *
     * @throws  InvalidArgumentException  When both ends are given and the end is not strictly after the start.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ?DateTimeImmutable $startsAt = null,
        private ?DateTimeImmutable $endsAt = null,
    ) {
        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            throw new InvalidArgumentException('A publication window must end after it starts.');
        }
    }

    /**
     * Build the window an entry carries when its author set no schedule at all.
     *
     * @return  self  A window open at both ends, so `contains()` holds for every instant.
     *
     * @since   2.0.0
     */
    public static function unbounded(): self
    {
        return new self();
    }

    /**
     * Return the instant from which the entry may be delivered.
     *
     * @return  ?DateTimeImmutable  Null when delivery starts the moment the entry is published.
     *
     * @since   2.0.0
     */
    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    /**
     * Return the instant at which delivery of the entry stops.
     *
     * @return  ?DateTimeImmutable  Null when the entry stays deliverable for as long as it is published.
     *
     * @since   2.0.0
     */
    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * Decide whether the window is open at the given instant.
     *
     * The window is half-open: the start instant falls inside it and the end instant does not, so two
     * back-to-back windows never both claim the same moment.
     *
     * @param   DateTimeImmutable  $instant  Moment being tested, normally the current request time.
     *
     * @return  bool  True while the entry is within its schedule.
     *
     * @since   2.0.0
     */
    public function contains(DateTimeImmutable $instant): bool
    {
        if ($this->startsAt !== null && $instant < $this->startsAt) {
            return false;
        }

        return $this->endsAt === null || $instant < $this->endsAt;
    }
}
