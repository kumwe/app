<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldPresentation::class)]
#[CoversClass(FieldPresentationRequest::class)]
/**
 * Pins the bounded and non-widening semantic model handed from field presenters to core templates.
 *
 * @since  2.0.0
 */
final class FieldPresentationTest extends TestCase
{
    /**
     * Retained input cannot exceed the same one-mebibyte budget as a generated form submission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsOversizedRetainedInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('input exceeds one mebibyte');

        new FieldPresentation(
            'code',
            'Code',
            FieldPresentationContext::Detail,
            FieldWidget::Output,
            '',
            str_repeat('x', 1_048_576),
            false,
            false,
        );
    }

    /**
     * Many large presenter strings are refused before one oversized canonical buffer is allocated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAggregateOversizedRetainedInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('input exceeds one mebibyte');

        new FieldPresentation(
            'code',
            'Code',
            FieldPresentationContext::Detail,
            FieldWidget::Output,
            '',
            array_fill(0, 256, str_repeat('x', 4096)),
            false,
            false,
        );
    }

    /**
     * An editor widget and its editable flag cannot contradict what core Twig will render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsNonEditableInputWidget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inconsistent editor state');

        new FieldPresentation(
            'code',
            'Code',
            FieldPresentationContext::Detail,
            FieldWidget::Text,
            '',
            null,
            false,
            false,
        );
    }

    /**
     * An allow-listed attribute name cannot carry an unbounded extension-supplied value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnboundedWidgetAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid widget attribute');

        new FieldPresentation(
            'code',
            'Code',
            FieldPresentationContext::Create,
            FieldWidget::Text,
            '',
            null,
            true,
            false,
            attributes: ['autocomplete' => str_repeat('x', 192)],
        );
    }

    /**
     * A request cannot select a presenter with type metadata different from the field declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMismatchedRequestType(): void
    {
        $field = new FieldDefinition('code', 'Code', 'acme.editor.code');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mismatched field-type metadata');
        new FieldPresentationRequest(
            $field,
            new FieldTypeDefinition(
                'acme.editor.other',
                'Other',
                'Another extension-owned string.',
                'string',
                'string',
            ),
            FieldPresentationContext::Detail,
        );
    }

    /**
     * Read-only and immutable field contracts remain authoritative over a caller's editable hint.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditingPermissionIncludesImmutableFieldRules(): void
    {
        $type = self::type();
        $ordinary = new FieldPresentationRequest(
            new FieldDefinition('code', 'Code', $type->id),
            $type,
            FieldPresentationContext::Update,
            editable: true,
        );
        $immutable = new FieldPresentationRequest(
            new FieldDefinition('code', 'Code', $type->id, immutableAfterCreate: true),
            $type,
            FieldPresentationContext::Update,
            editable: true,
        );
        $readOnly = new FieldPresentationRequest(
            new FieldDefinition('code', 'Code', $type->id, readOnly: true),
            $type,
            FieldPresentationContext::Create,
            editable: true,
        );

        self::assertTrue($ordinary->permitsEditing());
        self::assertFalse($immutable->permitsEditing());
        self::assertFalse($readOnly->permitsEditing());
    }

    /**
     * Build the field type shared by the editability cases.
     *
     * @return  FieldTypeDefinition  Extension-owned scalar type.
     *
     * @since   2.0.0
     */
    private static function type(): FieldTypeDefinition
    {
        return new FieldTypeDefinition(
            'acme.editor.code',
            'Code',
            'A bounded extension-owned code.',
            'string',
            'string',
        );
    }
}
