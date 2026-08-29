<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessFormInputMapper;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationModel;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessFormInputMapper::class)]
/**
 * Pins the browser input mapper's allow-list, its per-widget coercions, and every refusal path.
 *
 * The mapper is the delivery-side gate between a generated browser form and a typed record command:
 * only handles present and editable in the server-produced schema are admitted, exact numbers stay
 * strings, booleans and integers are narrowly parsed, JSON text areas are decoded under explicit depth
 * and byte limits, and an empty secret disappears rather than overwriting a stored one.
 *
 * @since  2.0.0
 */
final class BusinessFormInputMapperTest extends TestCase
{
    /**
     * The strategy-typed entry point admits editable handles and applies every widget coercion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapCoercesEveryEditableWidgetAgainstItsPresentation(): void
    {
        $fields = [
            $this->presentation('title', FieldWidget::Text),
            $this->presentation('count', FieldWidget::Integer),
            $this->presentation('active', FieldWidget::Checkbox),
            $this->presentation('price', FieldWidget::Money),
            $this->presentation('mass', FieldWidget::Quantity),
            $this->presentation('due', FieldWidget::ZonedDateTime),
            $this->presentation('meta', FieldWidget::Json),
            $this->presentation('rows', FieldWidget::Collection),
            $this->presentation('token', FieldWidget::Secret),
            $this->presentation('note', FieldWidget::Textarea, required: false),
        ];

        $mapped = (new BusinessFormInputMapper())->map([
            'title' => 'Invoice',
            'count' => '42',
            'active' => '1',
            'price' => ['amount' => '19.99', 'currency' => 'NAD'],
            'mass' => ['amount' => '2.5', 'unit' => 'kg'],
            'due' => ['instant' => '2026-08-18T09:00:00', 'timezone' => 'Africa/Windhoek'],
            'meta' => '{"kind":"expense"}',
            'rows' => [['line' => 1]],
            'token' => '',
            'note' => '',
        ], $fields);

        self::assertSame([
            'title' => 'Invoice',
            'count' => 42,
            'active' => true,
            'price' => ['amount' => '19.99', 'currency' => 'NAD'],
            'mass' => ['amount' => '2.5', 'unit' => 'kg'],
            'due' => ['instant' => '2026-08-18T09:00:00', 'timezone' => 'Africa/Windhoek'],
            'meta' => ['kind' => 'expense'],
            'rows' => [['line' => 1]],
            'note' => null,
        ], $mapped);
        self::assertArrayNotHasKey('token', $mapped, 'An empty secret must not overwrite the stored one.');
    }

    /**
     * A handle outside the authorized presentation list, or a malformed schema entry, is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapRefusesUnknownReadOnlyAndMalformedSchemaEntries(): void
    {
        $mapper = new BusinessFormInputMapper();

        try {
            $mapper->map(['ghost' => 'x'], [$this->presentation('title', FieldWidget::Text)]);
            self::fail('An unknown handle must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('unavailable field', $exception->getMessage());
        }

        try {
            $mapper->map(['display' => 'x'], [$this->presentation('display', FieldWidget::Output, editable: false)]);
            self::fail('A read-only presentation is not an editable handle.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('unavailable field', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema is invalid');
        /** @phpstan-ignore argument.type (the runtime guard against a non-presentation entry is the subject) */
        $mapper->map(['title' => 'x'], ['not a presentation']);
    }

    /**
     * The field-count and canonical-byte bounds refuse an unbounded submission before any coercion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapRefusesUnboundedSubmissions(): void
    {
        $mapper = new BusinessFormInputMapper();
        $wide = [];
        for ($index = 0; $index < 257; $index++) {
            $wide['field_' . $index] = 'x';
        }

        try {
            $mapper->map($wide, []);
            self::fail('More than 256 submitted fields must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('field bound', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one mebibyte');
        $mapper->map(
            ['title' => str_repeat('a', 1_048_577)],
            [$this->presentation('title', FieldWidget::Text)],
        );
    }

    /**
     * Integer parsing accepts canonical spellings only and stays inside the platform range.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIntegerParsingIsCanonicalAndRangeBounded(): void
    {
        $mapper = new BusinessFormInputMapper();
        $fields = [$this->presentation('count', FieldWidget::Integer)];

        self::assertSame(['count' => -7], $mapper->map(['count' => '-7'], $fields));
        self::assertSame(['count' => 9], $mapper->map(['count' => 9], $fields));

        foreach (['007', '1.5', '+5', 'seven', true] as $malformed) {
            try {
                $mapper->map(['count' => $malformed], $fields);
                self::fail('A non-canonical integer must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('integer field is invalid', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported range');
        $mapper->map(['count' => '99999999999999999999'], $fields);
    }

    /**
     * Booleans admit exactly the closed browser representations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBooleanParsingAdmitsOnlyTheClosedRepresentations(): void
    {
        $mapper = new BusinessFormInputMapper();
        $fields = [$this->presentation('active', FieldWidget::Checkbox)];

        self::assertSame(['active' => true], $mapper->map(['active' => true], $fields));
        self::assertSame(['active' => true], $mapper->map(['active' => 1], $fields));
        self::assertSame(['active' => false], $mapper->map(['active' => '0'], $fields));
        self::assertSame(['active' => false], $mapper->map(['active' => false], $fields));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('boolean field is invalid');
        $mapper->map(['active' => 'yes'], $fields);
    }

    /**
     * Exact composites keep their two closed members and refuse every other shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositeAndZonedShapesAreClosed(): void
    {
        $mapper = new BusinessFormInputMapper();
        $money = [$this->presentation('price', FieldWidget::Money)];
        $zoned = [$this->presentation('due', FieldWidget::ZonedDateTime)];

        foreach (
            [
                ['amount' => '10'],
                ['amount' => 10, 'currency' => 'NAD'],
                ['amount' => '1,5', 'currency' => 'NAD'],
                ['amount' => '1.5', 'currency' => ''],
                ['amount' => '1.5', 'currency' => str_repeat('N', 33)],
                ['amount' => '1.5', 'currency' => 'NAD', 'extra' => true],
                'not a composite',
            ] as $malformed
        ) {
            try {
                $mapper->map(['price' => $malformed], $money);
                self::fail('A malformed exact composite must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('exact composite field is invalid', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zoned date-time field is invalid');
        $mapper->map(['due' => ['instant' => '2026-08-18T09:00:00', 'timezone' => 'Mars']], $zoned);
    }

    /**
     * Structured editors decode bounded JSON and refuse depth, size and scalar decodes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStructuredParsingIsBounded(): void
    {
        $mapper = new BusinessFormInputMapper();
        $fields = [$this->presentation('meta', FieldWidget::Json)];

        self::assertSame(['meta' => ['a' => 1]], $mapper->map(['meta' => ['a' => 1]], $fields));

        try {
            $mapper->map(['meta' => 7], $fields);
            self::fail('A non-string, non-array structured value must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('invalid or unbounded', $exception->getMessage());
        }

        try {
            $mapper->map(['meta' => '"scalar"'], $fields);
            self::fail('A structured field must decode to an array or object.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('decode to an array or object', $exception->getMessage());
        }

        $this->expectException(JsonException::class);
        $mapper->map(['meta' => str_repeat('[', 17) . str_repeat(']', 17)], $fields);
    }

    /**
     * Text widgets require bounded strings rather than coercing arrays or numbers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTextParsingRefusesNonStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('text field is invalid or unbounded');
        (new BusinessFormInputMapper())->map(
            ['title' => ['not' => 'text']],
            [$this->presentation('title', FieldWidget::Text)],
        );
    }

    /**
     * The exported-model entry point revalidates each member and applies the identical coercions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapSurfaceRevalidatesExportedModelsAndCoerces(): void
    {
        $mapper = new BusinessFormInputMapper();
        $models = [
            ['handle' => 'title', 'widget' => 'text', 'editable' => true, 'required' => true],
            ['handle' => 'count', 'widget' => 'integer', 'editable' => true, 'required' => false],
            ['handle' => 'token', 'widget' => 'secret', 'editable' => true, 'required' => false],
        ];

        self::assertSame(
            ['title' => 'Invoice', 'count' => null],
            $mapper->mapSurface(['title' => 'Invoice', 'count' => '', 'token' => ''], $models),
        );
    }

    /**
     * A malformed exported model, an unknown handle, and a read-only submission are each refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapSurfaceRefusesMalformedModelsUnknownHandlesAndReadOnlyFields(): void
    {
        $mapper = new BusinessFormInputMapper();

        foreach (
            [
                [['handle' => 'Bad Handle', 'widget' => 'text', 'editable' => true, 'required' => false]],
                [['handle' => 'title', 'widget' => 'iframe', 'editable' => true, 'required' => false]],
                [['handle' => 'title', 'widget' => 'text', 'editable' => 'yes', 'required' => false]],
                [['handle' => 'title', 'widget' => 'text', 'editable' => true]],
            ] as $models
        ) {
            try {
                $mapper->mapSurface(['title' => 'x'], $models);
                self::fail('A malformed exported field model must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('form model is invalid', $exception->getMessage());
            }
        }

        try {
            $mapper->mapSurface(
                ['ghost' => 'x'],
                [['handle' => 'title', 'widget' => 'text', 'editable' => true, 'required' => false]],
            );
            self::fail('An unknown handle must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('unavailable field', $exception->getMessage());
        }

        try {
            $mapper->mapSurface(
                ['display' => 'x'],
                [['handle' => 'display', 'widget' => 'output', 'editable' => false, 'required' => false]],
            );
            self::fail('A read-only field cannot be submitted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('read-only business field', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('read-only business field');
        $mapper->mapSurface(
            ['display' => 'x'],
            [['handle' => 'display', 'widget' => 'output', 'editable' => true, 'required' => false]],
        );
    }

    /**
     * The exported-model entry point keeps the same canonical size bound as the typed one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapSurfaceRefusesAnOversizedSubmission(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one mebibyte');
        (new BusinessFormInputMapper())->mapSurface(
            ['title' => str_repeat('a', 1_048_577)],
            [['handle' => 'title', 'widget' => 'text', 'editable' => true, 'required' => false]],
        );
    }

    /**
     * Build one authorized field presentation for the widget under test.
     *
     * @param   string       $handle    Stable field handle the browser submits under.
     * @param   FieldWidget  $widget    Core-owned semantic widget being exercised.
     * @param   bool         $editable  Whether the server-produced schema admits an editor.
     * @param   bool         $required  Whether empty input is invalid for the field.
     *
     * @return  FieldPresentationModel  Minimal valid presentation for the mapper's allow-list.
     *
     * @since   2.0.0
     */
    private function presentation(
        string $handle,
        FieldWidget $widget,
        bool $editable = true,
        bool $required = true,
    ): FieldPresentationModel {
        return new FieldPresentationModel(
            $handle,
            ucfirst($handle),
            FieldPresentationContext::Update,
            $editable ? $widget : FieldWidget::Output,
            '',
            null,
            $editable,
            $required,
        );
    }
}
