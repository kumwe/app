<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordView as BusinessRecordViewContract;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;

/**
 * Disclosure-safe projection of one stored business record, as the read side hands it to a caller.
 *
 * A `BusinessRecord` carries whatever its row holds, including fields the actor may not see and
 * entity references stored as internal record keys. This view is where that is narrowed for release:
 * `fromRecord()` drops fields the pinned definition marks as not read-visible or whose visibility condition
 * does not hold for this record, restricts what is left to the requested projection, and omits restricted,
 * secret and unresolved-reference values. No ordinary business value doubles as disclosure metadata.
 * `BusinessRecordReadRepository::view()` returns one of these and `RecordBrowseResult` carries a page
 * of them; nothing outside the read side should assemble one from a raw record.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordView implements BusinessRecordViewContract
{
    /**
     * Field values the caller is allowed to see, keyed by field handle.
     *
     * A field absent from the map was never populated, was withheld, is not read-visible, or fell outside
     * the requested projection.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Related and owned-line projections attached to this record, keyed by relationship handle.
     *
     * Empty unless the browse request asked for includes, which the read side attaches with
     * `withIncludes()` once the whole page has been projected.
     *
     * @var    array<string, list<BusinessRecordRelationView>>
     * @since  2.0.0
     */
    public array $includes;

    /**
     * Capture one already-narrowed projection of a stored record.
     *
     * The constructor performs no filtering of its own: it trusts that read visibility, projection
     * and omission have already been applied, which is why `fromRecord()` is the sanctioned way in.
     *
     * @param   string                                           $definitionId            Entity-type definition UUID.
     * @param   int                                              $definitionVersion       Definition version the row
     *          was written under.
     * @param   string                                           $recordKey               Internal storage UUID of the
     *          row, which the read side matches resolved includes against.
     * @param   string                                           $recordId                Public identity callers
     *          address the record by.
     * @param   int                                              $version                 Optimistic version a
     *          mutation must echo back as its expected version.
     * @param   ?string                                          $siteIdentifier          Site the record is scoped
     *          to, or null when its type is not site-scoped.
     * @param   ?string                                          $organizationIdentifier  Organization the record is
     *          scoped to, or null when its type is not organization-scoped.
     * @param   ?string                                          $workflowState           Current workflow state, or
     *          null when the type declares no workflow.
     * @param   array<string, mixed>                             $values                  Visible values, already
     *          narrowed with withheld handles omitted.
     * @param   string                                           $createdBy               Actor that created the
     *          record.
     * @param   DateTimeImmutable                                $createdAt               Instant it was created.
     * @param   string                                           $updatedBy               Actor of the most recent
     *          write.
     * @param   DateTimeImmutable                                $updatedAt               Instant of that write.
     * @param   ?string                                          $archivedBy              Actor that archived the
     *          record, or null while it is unarchived.
     * @param   ?DateTimeImmutable                               $archivedAt              Instant of that archival, or
     *          null while the record is unarchived.
     * @param   ?string                                          $deletedBy               Actor that soft-deleted the
     *          record, or null while it is present.
     * @param   ?DateTimeImmutable                               $deletedAt               Instant of that deletion, or
     *          null while the record is present.
     * @param   array<string, list<BusinessRecordRelationView>>  $includes                Related-record projections
     *          keyed by relationship handle.
     *
     * @throws  InvalidArgumentException  When includes are malformed or exceed the delivery bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public int $version,
        public ?string $siteIdentifier,
        public ?string $organizationIdentifier,
        public ?string $workflowState,
        array $values,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
        public ?string $archivedBy,
        public ?DateTimeImmutable $archivedAt,
        public ?string $deletedBy,
        public ?DateTimeImmutable $deletedAt,
        array $includes = [],
    ) {
        if (count($includes) > 4) {
            throw new InvalidArgumentException('A business-record view has too many relationship includes.');
        }
        $includedRows = 0;
        foreach ($includes as $handle => $rows) {
            if (
                preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || !array_is_list($rows)
            ) {
                throw new InvalidArgumentException('A business-record view has an invalid relationship include.');
            }
            $includedRows += count($rows);
            foreach ($rows as $row) {
                if (!$row instanceof BusinessRecordRelationView) {
                    throw new InvalidArgumentException('A business-record view has an invalid related record.');
                }
            }
        }
        if ($includedRows > 1000) {
            throw new InvalidArgumentException('A business-record view exceeds the included-row limit.');
        }
        $this->values = $values;
        $this->includes = $includes;
    }

    /**
     * Return the published definition identity carried by this host view.
     *
     * @return  string  Entity-type definition UUID.
     *
     * @since   2.0.0
     */
    public function definitionIdentifier(): string
    {
        return $this->definitionId;
    }

    /**
     * Return the published definition version the record was written under.
     *
     * @return  int  Positive definition version.
     *
     * @since   2.0.0
     */
    public function definitionVersion(): int
    {
        return $this->definitionVersion;
    }

    /**
     * Return the public record identity exposed to callers.
     *
     * @return  string  Public record identifier rather than the internal storage key.
     *
     * @since   2.0.0
     */
    public function recordIdentifier(): string
    {
        return $this->recordId;
    }

    /**
     * Return the optimistic record version a mutation must echo.
     *
     * @return  int  Current optimistic version.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Return the site scope admitted for this view.
     *
     * @return  ?string  Site identifier, or null for a record without site scope.
     *
     * @since   2.0.0
     */
    public function siteIdentifier(): ?string
    {
        return $this->siteIdentifier;
    }

    /**
     * Return the organization scope admitted for this view.
     *
     * @return  ?string  Organization identifier, or null for a record without organization scope.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(): ?string
    {
        return $this->organizationIdentifier;
    }

    /**
     * Return the record's current workflow state.
     *
     * @return  ?string  Workflow state, or null when the definition declares no workflow.
     *
     * @since   2.0.0
     */
    public function workflowState(): ?string
    {
        return $this->workflowState;
    }

    /**
     * Return only the field values admitted for disclosure.
     *
     * @return  array<string, mixed>  Policy-narrowed values keyed by field handle.
     *
     * @since   2.0.0
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Return the instant of the most recent stored write.
     *
     * @return  DateTimeImmutable  Update instant carried by the projected record.
     *
     * @since   2.0.0
     */
    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Project a stored record into the view a caller may be shown.
     *
     * Narrowing happens only when $definition is supplied: with no definition the stored values pass
     * through untouched, so every release path hands over the definition the row was pinned to. With
     * a definition in hand, omitting $resolvedValues withholds every entity-reference field, because
     * the value stored in the row is an internal record key that must not leave the read side.
     *
     * @param   BusinessRecord             $record          Stored record to project.
     * @param   list<string>               $projection      Field handles to keep; an empty list keeps every
     *          read-visible field.
     * @param   ?EntityTypeDefinition      $definition      Definition the row was pinned to, supplying read
     *          visibility, conditional visibility and per-field sensitivity; null applies no narrowing at all.
     * @param   array<string, mixed>|null  $resolvedValues  Values whose entity references already carry the
     *          target's public identity; null omits every reference field instead.
     * @param   ?FieldDisclosurePlan       $disclosure      Explicit field allow-list; null preserves the
     *          legacy definition-only projection.
     * @param   FieldAccessUsage           $usage           Exact read surface whose disclosure set applies.
     *
     * @return  self  View over the narrowed values, with no includes attached yet.
     *
     * @since   2.0.0
     */
    public static function fromRecord(
        BusinessRecord $record,
        array $projection = [],
        ?EntityTypeDefinition $definition = null,
        ?array $resolvedValues = null,
        ?FieldDisclosurePlan $disclosure = null,
        FieldAccessUsage $usage = FieldAccessUsage::Detail,
    ): self {
        $values = $resolvedValues ?? $record->values();
        if ($definition !== null) {
            $values = array_intersect_key($values, RecordFieldVisibility::fields(
                $definition,
                $record->values(),
            ));
        }
        if ($projection !== []) {
            $values = array_intersect_key($values, array_fill_keys($projection, true));
        }
        if ($disclosure !== null) {
            $values = array_intersect_key($values, array_fill_keys($disclosure->fields($usage), true));
        }
        foreach ($definition?->fields() ?? [] as $field) {
            if (
                $resolvedValues === null && $field->type === 'core.entity_reference'
                && array_key_exists($field->handle, $values)
            ) {
                unset($values[$field->handle]);
            }
            if (
                array_key_exists($field->handle, $values)
                && in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                unset($values[$field->handle]);
            }
        }

        return new self(
            $record->definitionId,
            $record->definitionVersion,
            $record->recordKey,
            $record->recordId,
            $record->version,
            $record->scope->siteIdentifier,
            $record->scope->organizationIdentifier,
            $record->workflowState,
            $values,
            $record->createdBy,
            $record->createdAt,
            $record->updatedBy,
            $record->updatedAt,
            $record->archivedBy,
            $record->archivedAt,
            $record->deletedBy,
            $record->deletedAt,
        );
    }

    /**
     * Return a copy of this view with its related-record projections attached.
     *
     * A browse resolves includes for the whole page in one pass once every row has been projected,
     * so the includes arrive after construction rather than through it.
     *
     * @param   array<string, list<BusinessRecordRelationView>>  $includes  Related and owned-line projections
     *          keyed by relationship handle.
     *
     * @return  self  Copy carrying the same identity and values with the supplied includes.
     *
     * @since   2.0.0
     */
    public function withIncludes(array $includes): self
    {
        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->version,
            $this->siteIdentifier,
            $this->organizationIdentifier,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $this->updatedBy,
            $this->updatedAt,
            $this->archivedBy,
            $this->archivedAt,
            $this->deletedBy,
            $this->deletedAt,
            $includes,
        );
    }
}
