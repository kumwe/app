<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;

/**
 * The declarative temporal lock: refuses a mutation whose declared posting date is in a closed period.
 *
 * A definition opts in by marking exactly one of its date fields `posting_date` — a definition that
 * declares none is untouched by the whole mechanism, and this guard returns before reading anything.
 * For a declared definition the guard is evaluated **before the mutation fence is taken**: every
 * mutation path through `BusinessRecordService` — create, update, archive, delete, restore, relate,
 * unrelate, reorder, document writes, and custom actions dispatched by the business surface — calls it
 * ahead of its transaction, so a closed period refuses cheaply, without acquiring the definition's
 * exclusive installation lock. Both sides of a mutation are judged: the posting date the record
 * already stores, and the posting date the submitted values would store — which is what refuses a
 * creation backdated into a closed period as firmly as an edit of an existing row. A create that omits
 * the field falls back to the field's declared static default, since that is the value the row would
 * be given.
 *
 * Workflow transitions are deliberately **not** guarded. A transition writes only the record's
 * workflow state, its update stamps and its version — `BusinessRecord::transitioned()` never touches
 * declared field values — so a state move does not alter the posting-dated content this lock protects;
 * an extension that wants period-frozen state moves gates its transition capabilities or conditions
 * instead. Custom actions are guarded, because an extension handler may write anything.
 *
 * Two further consequences are accepted and intended. A malformed submitted posting value is ignored
 * here rather than refused, so the ordinary validation path reports it as a field violation instead of
 * a period refusal. And because the guard reads the world as it stands at evaluation, an idempotent
 * replay arriving after the period closed is refused like any other mutation — a closed period answers
 * every arrival identically.
 *
 * @since  2.0.0
 */
final readonly class PostingPeriodLock
{
    /**
     * Wire the lock to the period declarations and the codec that reads submitted date values.
     *
     * @param  PostingPeriodRepository  $periods  Declarations consulted for a closed range over each
     *         posting instant.
     * @param  RecordValueCodec         $values   Codec normalising a submitted wire value into the
     *         domain date value the row would store.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PostingPeriodRepository $periods,
        private RecordValueCodec $values,
    ) {
    }

    /**
     * Refuse the mutation when any posting date it touches falls inside a closed period.
     *
     * @param   EntityTypeDefinition  $definition       Installed definition the mutation addresses;
     *          its posting-date declaration decides whether anything is evaluated at all.
     * @param   RecordScope           $scope            Site and organization the mutation is confined
     *          to, which is the scope whose declared periods answer.
     * @param   ?BusinessRecord       $record           Stored record being mutated, or null while
     *          creating.
     * @param   array<string, mixed>  $submittedValues  Values the command submits; only the declared
     *          posting field is read.
     * @param   bool                  $creating         True for a create, which lets an omitted
     *          posting value fall back to the field's declared default.
     *
     * @return  void
     *
     * @throws  BusinessRecordPostingPeriodClosed  When a posting date the mutation touches falls
     *          inside a period whose status is closed.
     *
     * @since   2.0.0
     */
    public function assertMutationOpen(
        EntityTypeDefinition $definition,
        RecordScope $scope,
        ?BusinessRecord $record,
        array $submittedValues = [],
        bool $creating = false,
    ): void {
        $field = $definition->postingDateField();
        if ($field === null) {
            return;
        }
        $site = $scope->siteIdentifier ?? $definition->siteIdentifier;
        $instants = [];
        if ($record !== null) {
            $stored = $this->instantOf($record->values()[$field->handle] ?? null);
            if ($stored !== null) {
                $instants[] = $stored;
            }
        }
        if (array_key_exists($field->handle, $submittedValues)) {
            $submitted = $this->normalizedInstant($definition, $field, $submittedValues[$field->handle]);
            if ($submitted !== null) {
                $instants[] = $submitted;
            }
        } elseif ($creating && $field->default !== null) {
            $defaulted = $this->normalizedInstant($definition, $field, $field->default);
            if ($defaulted !== null) {
                $instants[] = $defaulted;
            }
        }
        foreach ($instants as $instant) {
            $closed = $this->periods->closedPeriodContaining(
                $site,
                $scope->organizationIdentifier,
                $instant,
            );
            if ($closed !== null) {
                throw new BusinessRecordPostingPeriodClosed($closed->key, $instant);
            }
        }
    }

    /**
     * Normalise one submitted wire value into the instant it would be stored as.
     *
     * @param   EntityTypeDefinition  $definition  Definition supplying the codec's site and identity
     *          coordinates.
     * @param   FieldDefinition       $field       Declared posting-date field being read.
     * @param   mixed                 $value       Value as the command submitted it.
     *
     * @return  ?DateTimeImmutable  The instant, or null when the value is null, malformed, or of a
     *          shape the posting types do not produce — malformed input is the validation path's to
     *          refuse.
     *
     * @since   2.0.0
     */
    private function normalizedInstant(
        EntityTypeDefinition $definition,
        FieldDefinition $field,
        mixed $value,
    ): ?DateTimeImmutable {
        try {
            $normalized = $this->values->normalize(
                $field,
                $value,
                $definition->siteIdentifier,
                $definition->id,
                '',
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->instantOf($normalized);
    }

    /**
     * Read the absolute instant out of a normalised date value.
     *
     * @param   mixed  $value  Stored or normalised value of the declared posting field.
     *
     * @return  ?DateTimeImmutable  The instant a date, instant or zoned value carries, or null for
     *          anything else — including an absent value, which no period can contain.
     *
     * @since   2.0.0
     */
    private function instantOf(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof ZonedDateTimeValue) {
            return $value->instant;
        }

        return null;
    }
}
