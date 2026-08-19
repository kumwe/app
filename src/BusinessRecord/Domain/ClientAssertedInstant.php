<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The instant a client says the work happened, recorded beside the server's own and never in place of it.
 *
 * A terminal that captures a sale on Friday and submits it on Monday is asserting something the platform
 * cannot check: its own clock. Decision D14 requires that such a claim have a declared place to live, and
 * this is that place. The type exists so the assertion can be carried, read and audited without ever
 * being mistaken for the authoritative instant — `BusinessRecordService` keeps stamping every row from
 * its injected clock, and nothing here replaces that.
 *
 * **What this instant is never used for**, because a client's clock cannot be trusted with any of it:
 *
 * - **Ordering.** Events are ordered by allocated sequence, which is a server-side fact. A document
 *   captured earlier and submitted later is ordered where it arrived, and its capture instant explains
 *   the gap rather than reopening the sequence. Arrival order is not capture order and neither is
 *   negotiable by a caller.
 * - **Expiry.** An idempotency claim's replay and retention horizons run from the server's instant, so a
 *   terminal cannot lengthen its own replay window by asserting a later capture.
 * - **Period assignment.** Which accounting period a document falls in is decided from server-held
 *   declarations, so a client cannot post into a period by asserting a date inside it.
 * - **Numbering.** A document number is allocated by the receiving command from a server-side counter,
 *   so capture order can never reorder a statutory sequence.
 *
 * `ClientAssertedInstantBoundaryTest` proves each of those mechanically rather than leaving them as
 * intentions: it enumerates the paths that consume an instant for one of those four decisions and fails
 * the build if any of them can reach this type.
 *
 * **Late and out-of-order arrival is accepted, not tolerated as an edge case.** A command carrying a
 * capture instant days behind the server's is an ordinary command: it is validated, numbered, sequenced
 * and audited exactly as one captured a second ago, and the assertion is recorded so a reader can tell
 * the two apart afterwards.
 *
 * @since  2.0.0
 */
final readonly class ClientAssertedInstant
{
    /**
     * Canonical text form, which is also the grammar `fromPortableString()` reads back.
     *
     * @var    string
     * @since  2.0.0
     */
    public const INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Earliest instant a client may assert, below which the value is a corrupt clock rather than a claim.
     *
     * @var    string
     * @since  2.0.0
     */
    private const EARLIEST = '2000-01-01T00:00:00.000000+00:00';

    /**
     * Latest instant a client may assert, above which the value is a corrupt clock rather than a claim.
     *
     * The bound is deliberately generous and is not measured against the server's clock. A terminal whose
     * clock is a week fast is exactly the situation this type exists to record; only a value that cannot
     * be a clock at all is refused.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LATEST = '2100-01-01T00:00:00.000000+00:00';

    /**
     * Capture one client-asserted instant, normalized to UTC at microsecond precision.
     *
     * @param   DateTimeImmutable  $capturedAt  Instant the client says the work happened, in any zone.
     *
     * @throws  InvalidArgumentException  When the instant falls outside the representable range, which
     *          means the value is a corrupt clock reading rather than a claim about when work happened.
     *
     * @since   2.0.0
     */
    public function __construct(public DateTimeImmutable $capturedAt)
    {
        $utc = $capturedAt->setTimezone(new DateTimeZone('UTC'));
        if (
            $utc < new DateTimeImmutable(self::EARLIEST)
            || $utc >= new DateTimeImmutable(self::LATEST)
        ) {
            throw new InvalidArgumentException('A client-asserted capture instant is outside the recordable range.');
        }
    }

    /**
     * Read a client-asserted instant from the text a caller submitted.
     *
     * @param   string  $value  RFC 3339 instant with an explicit offset.
     *
     * @return  self  The claim, normalized to UTC.
     *
     * @throws  InvalidArgumentException  When the text is not an RFC 3339 instant with an offset, or the
     *          instant it names is outside the recordable range.
     *
     * @since   2.0.0
     */
    public static function fromPortableString(string $value): self
    {
        $grammar = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?'
            . '(?:Z|[+-][0-9]{2}:[0-9]{2})$/D';
        if (preg_match($grammar, $value) !== 1) {
            throw new InvalidArgumentException('A client-asserted capture instant must be an RFC 3339 instant.');
        }
        $parsed = date_create_immutable($value);
        if ($parsed === false) {
            throw new InvalidArgumentException('A client-asserted capture instant must be an RFC 3339 instant.');
        }

        return new self($parsed);
    }

    /**
     * Spell the claim in the one canonical form every surface and the audit trail carry it in.
     *
     * @return  string  UTC instant at microsecond precision, such as `2026-08-14T09:30:00.000000+00:00`.
     *
     * @since   2.0.0
     */
    public function toPortableString(): string
    {
        return $this->capturedAt->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT_FORMAT);
    }

    /**
     * Export the claim in a shape that says whose clock it came from.
     *
     * The `asserted_by_client` marker is unconditional and the instant sits under `captured_at` rather
     * than at the top level, so a reader of an audit entry or an event payload cannot mistake this for
     * the server's own instant, which is recorded separately and always wins.
     *
     * @return  array{asserted_by_client: true, captured_at: string}  The claim and its provenance.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'asserted_by_client' => true,
            'captured_at' => $this->toPortableString(),
        ];
    }
}
