<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One named, keyed range an extension declared over a site's posting timeline.
 *
 * Core deliberately knows nothing about fiscal calendars: a posting period here is only a stable key,
 * a half-open UTC instant range `[startsAt, endsAt)`, and an open-or-closed state. Which days make a
 * period, when it closes, who may close it and what re-opening means are the declaring extension's
 * rules, exercised through `PostingPeriodService`. The half-open convention is what keeps adjacent
 * declarations gap-free: a month ending at the next month's first midnight leaves no instant that
 * belongs to both or to neither.
 *
 * The row keeps only the current state; the history of closes and re-opens lives in the audit trail,
 * so closing again after a re-open replaces the close bookkeeping and clears the re-open bookkeeping.
 *
 * @since  2.0.0
 */
final readonly class PostingPeriod
{
    /**
     * First instant inside the range, inclusive, normalised to UTC.
     *
     * @var    DateTimeImmutable
     * @since  2.0.0
     */
    public DateTimeImmutable $startsAt;

    /**
     * First instant past the range, exclusive, normalised to UTC.
     *
     * @var    DateTimeImmutable
     * @since  2.0.0
     */
    public DateTimeImmutable $endsAt;

    /**
     * Declare one period and refuse a declaration the lock could not evaluate.
     *
     * Both instants are normalised to UTC, so two spellings of the same moment compare equal however
     * the caller's adapter parsed them.
     *
     * @param   string               $siteIdentifier          Site whose posting timeline the range covers.
     * @param   ?string              $organizationIdentifier  Organization the range is confined to, or
     *          null when it covers every record on the site.
     * @param   string               $key                     Extension-declared stable key, such as a
     *          fiscal period code; unique per site and organization scope.
     * @param   DateTimeImmutable    $startsAt                First instant inside the range, inclusive.
     * @param   DateTimeImmutable    $endsAt                  First instant past the range, exclusive.
     * @param   PostingPeriodStatus  $status                  Whether the range currently refuses
     *          mutations.
     * @param   string               $closedBy                Actor recorded against the most recent close.
     * @param   DateTimeImmutable    $closedAt                Instant of the most recent close.
     * @param   ?string              $reopenedBy              Actor who re-opened the period, or null
     *          while it has not been re-opened since its last close.
     * @param   ?DateTimeImmutable   $reopenedAt              Instant of that re-open, or null with it.
     *
     * @throws  InvalidArgumentException  When the site, organization, key or actor is malformed, the
     *          re-open bookkeeping is half-recorded, or the range does not start strictly before it
     *          ends.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public string $key,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        public PostingPeriodStatus $status,
        public string $closedBy,
        public DateTimeImmutable $closedAt,
        public ?string $reopenedBy = null,
        public ?DateTimeImmutable $reopenedAt = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new InvalidArgumentException('A posting period site identifier is invalid.');
        }
        if (
            $organizationIdentifier !== null
            && ($organizationIdentifier === '' || strlen($organizationIdentifier) > 191)
        ) {
            throw new InvalidArgumentException('A posting period organization identifier is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A posting period key must be a bounded stable identifier.');
        }
        if ($closedBy === '' || strlen($closedBy) > 191) {
            throw new InvalidArgumentException('A posting period close requires a bounded actor identity.');
        }
        if (($reopenedBy === null) !== ($reopenedAt === null)) {
            throw new InvalidArgumentException('A posting period re-open records its actor and instant together.');
        }
        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt->setTimezone($utc);
        $this->endsAt = $endsAt->setTimezone($utc);
        if ($this->startsAt >= $this->endsAt) {
            throw new InvalidArgumentException('A posting period must start strictly before it ends.');
        }
    }

    /**
     * Decide whether an instant falls inside this period's half-open range.
     *
     * @param   DateTimeImmutable  $instant  Moment being classified; compared as an absolute instant,
     *          so its authoring timezone is irrelevant.
     *
     * @return  bool  True when `startsAt <= instant < endsAt`.
     *
     * @since   2.0.0
     */
    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->startsAt && $instant < $this->endsAt;
    }

    /**
     * Report whether the period currently refuses dated mutations.
     *
     * @return  bool  True when the status is `Closed`.
     *
     * @since   2.0.0
     */
    public function isClosed(): bool
    {
        return $this->status === PostingPeriodStatus::Closed;
    }

    /**
     * Produce the period as closed, under a fresh close bookkeeping pair.
     *
     * The re-open bookkeeping is cleared rather than kept, because the row holds the current state
     * only — the sequence of closes and re-opens is the audit trail's record.
     *
     * @param   string             $actorId  Actor performing this close.
     * @param   DateTimeImmutable  $at       Instant recorded against it.
     *
     * @return  self  The same declared range, closed by the given actor.
     *
     * @since   2.0.0
     */
    public function closed(string $actorId, DateTimeImmutable $at): self
    {
        return new self(
            $this->siteIdentifier,
            $this->organizationIdentifier,
            $this->key,
            $this->startsAt,
            $this->endsAt,
            PostingPeriodStatus::Closed,
            $actorId,
            $at,
        );
    }

    /**
     * Produce the period as re-opened, keeping the close bookkeeping it is re-opening.
     *
     * @param   string             $actorId  Actor performing the re-open.
     * @param   DateTimeImmutable  $at       Instant recorded against it.
     *
     * @return  self  The same declared range, open again.
     *
     * @since   2.0.0
     */
    public function reopened(string $actorId, DateTimeImmutable $at): self
    {
        return new self(
            $this->siteIdentifier,
            $this->organizationIdentifier,
            $this->key,
            $this->startsAt,
            $this->endsAt,
            PostingPeriodStatus::Open,
            $this->closedBy,
            $this->closedAt,
            $actorId,
            $at,
        );
    }

    /**
     * Flatten the period into the document administrative surfaces print.
     *
     * @return  array<string, mixed>  Scope, key, range, status and bookkeeping under snake_case keys,
     *          with every instant rendered as an RFC 3339 UTC string.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'site' => $this->siteIdentifier,
            'organization' => $this->organizationIdentifier,
            'key' => $this->key,
            'starts_at' => $this->startsAt->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->endsAt->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->status->value,
            'closed_by' => $this->closedBy,
            'closed_at' => $this->closedAt->format('Y-m-d\TH:i:s\Z'),
            'reopened_by' => $this->reopenedBy,
            'reopened_at' => $this->reopenedAt?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
