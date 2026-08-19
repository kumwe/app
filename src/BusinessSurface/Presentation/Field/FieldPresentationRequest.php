<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;

/**
 * Validated, transport-free input handed to a field presentation strategy.
 *
 * @since  2.0.0
 */
final readonly class FieldPresentationRequest
{
    /**
     * Build a safe renderer request from declarative metadata and an already disclosed value.
     *
     * @param   FieldDefinition           $field     Field declaration from the pinned entity version.
     * @param   FieldTypeDefinition       $type      Immutable logical and storage family.
     * @param   FieldPresentationContext  $context   Exact render or edit context.
     * @param   mixed                     $value     Already validated and policy-disclosed typed value.
     * @param   string                    $locale    Bounded locale hint such as `en-NA`.
     * @param   list<string>              $errors    Caller-visible validation messages for this field.
     * @param   bool                      $editable  Whether server policy and conditions admit input now.
     *
     * @throws  InvalidArgumentException  When the field and type disagree, or locale or errors are malformed
     *          or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public FieldDefinition $field,
        public FieldTypeDefinition $type,
        public FieldPresentationContext $context,
        public mixed $value = null,
        public string $locale = 'en',
        public array $errors = [],
        public bool $editable = false,
    ) {
        if ($field->type !== $type->id) {
            throw new InvalidArgumentException('A field-presentation request has mismatched field-type metadata.');
        }
        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/D', $locale) !== 1) {
            throw new InvalidArgumentException('A field-presentation locale is invalid.');
        }
        if (count($errors) > 32) {
            throw new InvalidArgumentException('A field presentation contains too many errors.');
        }
        foreach ($errors as $error) {
            if (!is_string($error) || $error === '' || strlen($error) > 1000) {
                throw new InvalidArgumentException('A field-presentation error is invalid.');
            }
        }
    }

    /**
     * Report whether this exact context and field contract permit an input control.
     *
     * A presentation strategy may narrow this result but never widen it. Keeping the structural
     * read-only, computed, server-only, and immutable checks here prevents an extension presenter from
     * turning policy-safe metadata into a writable browser control.
     *
     * @return  bool  True only when the caller and immutable field contract both admit editing.
     *
     * @since   2.0.0
     */
    public function permitsEditing(): bool
    {
        return $this->context->edits()
            && $this->editable
            && !$this->field->readOnly
            && !$this->field->computed
            && !$this->field->serverOnly
            && !(
                $this->context === FieldPresentationContext::Update
                && $this->field->immutableAfterCreate
            );
    }
}
