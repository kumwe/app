<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessSurface\Presentation\Field\CoreFieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationInputFactory;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationConfiguration;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the core catalogue presenter emits only allow-listed semantic widgets with bounded data.
 *
 * @since  2.0.0
 */
#[CoversClass(CoreFieldPresenter::class)]
#[CoversClass(FieldPresentationInputFactory::class)]
final class CoreFieldPresenterTest extends TestCase
{
    /**
     * An editable enum maps its signed configuration onto closed selector options.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditableEnumPresentsClosedSelectorOptions(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input(
            'core.enum',
            'open',
            configuration: FieldPresentationConfiguration::fromArray(['options' => ['open', 'in_review']]),
        ));

        self::assertSame('status', $presentation->handle);
        self::assertSame('Status', $presentation->label);
        self::assertSame(FieldPresentationContext::Create, $presentation->context);
        self::assertSame(FieldWidget::Select, $presentation->widget);
        self::assertTrue($presentation->editable);
        self::assertFalse($presentation->required);
        self::assertSame('open', $presentation->display);
        self::assertSame('open', $presentation->inputValue);
        self::assertSame(
            [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'in_review', 'label' => 'In review'],
            ],
            $presentation->options,
        );
        self::assertSame([], $presentation->attributes);
    }

    /**
     * An editable secret keeps its write-only widget while disclosing no display or retained input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditableSecretDisclosesNoValue(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input('core.secret', 'hunter2'));

        self::assertSame(FieldWidget::Secret, $presentation->widget);
        self::assertTrue($presentation->editable);
        self::assertSame('', $presentation->display);
        self::assertNull($presentation->inputValue);
        self::assertSame([], $presentation->options);
    }

    /**
     * An editable integer carries its declared length plus exact numeric input bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditableIntegerCarriesNumericAttributes(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input('core.integer', 42, length: 5));

        self::assertSame(FieldWidget::Integer, $presentation->widget);
        self::assertSame('42', $presentation->display);
        self::assertSame(42, $presentation->inputValue);
        self::assertSame(['maxlength' => 5, 'step' => '1', 'inputmode' => 'numeric'], $presentation->attributes);
    }

    /**
     * An editable decimal groups display digits while retaining the exact string for its editor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditableDecimalKeepsExactStringInput(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input('core.decimal', '1234.5'));

        self::assertSame(FieldWidget::Decimal, $presentation->widget);
        self::assertSame('1,234.5', $presentation->display);
        self::assertSame('1234.5', $presentation->inputValue);
        self::assertSame(['inputmode' => 'decimal'], $presentation->attributes);
    }

    /**
     * Editable rich text uses the plain multi-line editor with its bounded row hint.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEditableRichTextUsesBoundedTextarea(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input('core.rich_text', "Hello\nWorld"));

        self::assertSame(FieldWidget::Textarea, $presentation->widget);
        self::assertSame("Hello\nWorld", $presentation->display);
        self::assertSame("Hello\nWorld", $presentation->inputValue);
        self::assertSame(['rows' => 8], $presentation->attributes);
    }

    /**
     * A read context always yields escaped output even when the caller marks the field editable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReadContextPresentsEscapedOutput(): void
    {
        $presentation = (new CoreFieldPresenter())->present(self::input(
            'core.text',
            'plain value',
            context: FieldPresentationContext::Detail,
        ));

        self::assertSame(FieldWidget::Output, $presentation->widget);
        self::assertFalse($presentation->editable);
        self::assertSame('plain value', $presentation->display);
        self::assertSame([], $presentation->options);
        self::assertSame([], $presentation->attributes);
    }

    /**
     * Enum configuration outside a closed list of strings is refused, not coerced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedEnumOptionsAreRefused(): void
    {
        $candidates = [
            FieldPresentationConfiguration::fromArray(['options' => 'open']),
            FieldPresentationConfiguration::fromArray(['options' => ['open', 7]]),
        ];

        foreach ($candidates as $configuration) {
            try {
                (new CoreFieldPresenter())->present(self::input('core.enum', 'open', configuration: $configuration));
                self::fail('Malformed enum presentation options must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('invalid presentation options', $exception->getMessage());
            }
        }
    }

    /**
     * The input factory refuses a field whose declared type contradicts the resolved type row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInputFactoryRefusesMismatchedTypeMetadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mismatched field-type metadata');

        FieldPresentationInputFactory::fromDefinition(
            new FieldDefinition('code', 'Code', 'core.text'),
            new FieldTypeDefinition('core.enum', 'Choice', 'A closed choice.', 'string', 'string'),
            FieldPresentationContext::Detail,
        );
    }

    /**
     * Build one canonical presenter input for a directly constructed core field.
     *
     * @param   string                          $fieldType      Core field-type identifier under test.
     * @param   mixed                           $value          Policy-disclosed field value.
     * @param   FieldPresentationContext        $context        Exact presentation context.
     * @param   ?int                            $length         Declared maximum length, when bounded.
     * @param   ?FieldPresentationConfiguration $configuration  Closed type-specific settings.
     *
     * @return  FieldPresentationInput  Editable presenter input for the requested type.
     *
     * @since   2.0.0
     */
    private static function input(
        string $fieldType,
        mixed $value,
        FieldPresentationContext $context = FieldPresentationContext::Create,
        ?int $length = null,
        ?FieldPresentationConfiguration $configuration = null,
    ): FieldPresentationInput {
        return new FieldPresentationInput(
            handle: 'status',
            label: 'Status',
            fieldType: $fieldType,
            required: false,
            readOnly: false,
            computed: false,
            serverOnly: false,
            immutableAfterCreate: false,
            context: $context,
            value: $value,
            locale: 'en',
            errors: [],
            editable: true,
            length: $length,
            configuration: $configuration,
        );
    }
}
