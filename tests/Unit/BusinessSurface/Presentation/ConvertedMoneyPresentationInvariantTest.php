<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\ClientAssertedInstant;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Decimal\ExactDecimalArithmetic;
use Kumwe\Conversion\Contract\MoneyConversionRequest;
use Kumwe\Conversion\Contract\MoneyConverter;
use Kumwe\Conversion\Value\MoneyExchangeRate;
use Kumwe\Conversion\Value\MoneyRoundingMode;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldPresentation::class)]
#[CoversClass(ClientAssertedInstant::class)]
/**
 * Pins the refusals that keep a converted figure and its evidence from coming apart in a presentation.
 *
 * The cross-surface test proves the rule holds on the surfaces that render one. These are the other
 * half: the ways a presentation carrying provenance can be malformed, each of which has to be refused
 * at construction rather than rendered. A figure that reaches a template already separated from its
 * rate is not recoverable there, so the model is where it has to be stopped.
 *
 * @since  2.0.0
 */
final class ConvertedMoneyPresentationInvariantTest extends TestCase
{
    /**
     * A converted amount cannot be offered as an editor or retained as input.
     *
     * The two cases here are the ones that reach the provenance rule at all. A presentation that is
     * read-only with an input widget, or editable with an output widget, is already refused by the
     * editor-state invariant that runs first, so the provenance rule never sees it — which is why those
     * combinations are asserted separately below rather than folded in here and mislabelled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedAmountIsRefusedAsAnythingButReadOnlyOutput(): void
    {
        $provenance = self::converted()->toArray();
        $display = self::converted()->toPortableString();

        foreach (
            [
                'an enabled editor' => [FieldWidget::Money, $display, null, true],
                'retained input' => [FieldWidget::Output, $display, ['amount' => '1.00'], false],
            ] as $case => [$widget, $text, $input, $editable]
        ) {
            try {
                self::presentation($widget, $text, $input, $editable, $provenance);
                self::fail('A converted amount was presented with ' . $case . '.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('read-only', $exception->getMessage(), $case);
            }
        }
    }

    /**
     * The editor-state invariant still runs first, so an inconsistent widget never reaches provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInconsistentEditorStateIsRefusedBeforeProvenanceIsConsulted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inconsistent editor state');
        self::presentation(
            FieldWidget::Money,
            self::converted()->toPortableString(),
            null,
            false,
            self::converted()->toArray(),
        );
    }

    /**
     * Provenance that cannot be read back as a whole converted amount is refused rather than trusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIncompleteProvenanceIsRefused(): void
    {
        $whole = self::converted()->toArray();
        unset($whole['rate']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('incomplete conversion provenance');
        self::presentation(FieldWidget::Output, self::converted()->toPortableString(), null, false, $whole);
    }

    /**
     * A display that is not the portable form of the provenance beside it is refused.
     *
     * This is the assertion that makes a surface rendering nothing but `display` correct: the two cannot
     * disagree, so there is no presentation in which the figure shows and the evidence does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADisplayThatContradictsItsProvenanceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('displayed with the provenance it carries');
        self::presentation(FieldWidget::Output, 'EUR 1234.56', null, false, self::converted()->toArray());
    }

    /**
     * An ordinary presentation still carries no provenance and exports the member as null.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOrdinaryPresentationCarriesNoProvenance(): void
    {
        $presentation = self::presentation(FieldWidget::Output, 'N$ 1,200.00', null, false, null);

        self::assertNull($presentation->provenance);
        self::assertNull($presentation->toArray()['provenance']);
    }

    /**
     * A client-asserted instant refuses text that is not an RFC 3339 instant with an offset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClientAssertedInstantRefusesTextThatIsNotAnInstant(): void
    {
        foreach (['2026-08-14', 'yesterday', '2026-08-14T09:30:00', ''] as $malformed) {
            try {
                ClientAssertedInstant::fromPortableString($malformed);
                self::fail('A capture instant accepted "' . $malformed . '".');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('RFC 3339', $exception->getMessage());
            }
        }

        self::assertSame(
            '2026-08-14T09:30:00.000000+00:00',
            ClientAssertedInstant::fromPortableString('2026-08-14T11:30:00+02:00')->toPortableString(),
        );
    }

    /**
     * A converted amount is recognised as the object, as its export, and as neither.
     *
     * Every surface asks this one question, so the answer has to be the same whichever form the value
     * arrived in. The member set has to match exactly: a structured value that merely carries a
     * `converted` flag is somebody else's data and is left alone rather than claimed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedAmountIsRecognisedInEitherFormAndNotMistakenForOtherData(): void
    {
        $converted = self::converted();

        self::assertSame($converted, ConvertedMoneyValue::detect($converted));
        self::assertSame(
            $converted->toArray(),
            ConvertedMoneyValue::detect($converted->toArray())?->toArray(),
        );

        foreach (
            [
                'a bare figure' => '1234.56',
                'nothing at all' => null,
                'a stored money pair' => ['amount' => '25000.00', 'currency' => 'ZAR'],
                'an unrelated flag' => ['converted' => true],
                'a near miss with one member short' => [
                    'converted' => true,
                    'value' => ['amount' => '1.00', 'currency' => 'EUR'],
                    'source' => ['amount' => '1.00', 'currency' => 'EUR'],
                    'rate' => [],
                ],
            ] as $case => $value
        ) {
            self::assertNull(ConvertedMoneyValue::detect($value), $case . ' was read as a converted amount.');
        }
    }

    /**
     * An export carrying the declared members but contradicting itself is refused, not ignored.
     *
     * Returning null there would let a figure that says it is converted reach a surface as an ordinary
     * value, which is the one outcome the marker exists to prevent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExportThatClaimsToBeConvertedAndCannotProveItIsRefused(): void
    {
        $broken = self::converted()->toArray();
        $broken['rate']['rate'] = '0.99999999';

        $this->expectException(InvalidArgumentException::class);
        ConvertedMoneyValue::detect($broken);
    }

    /**
     * A capture instant whose text is well formed but names no real date is refused.
     *
     * The grammar admits `2026-13-45`, because a month is two digits by shape. Only the calendar can
     * reject it, so the parse result is checked rather than assumed from the pattern.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACaptureInstantThatParsesToNoRealDateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RFC 3339');
        ClientAssertedInstant::fromPortableString('2026-13-45T00:00:00+00:00');
    }

    /**
     * Build one field presentation with the members these assertions vary.
     *
     * @param   FieldWidget            $widget      Widget the presenter chose.
     * @param   string                 $display     Escaped display text.
     * @param   mixed                  $inputValue  Retained editor input.
     * @param   bool                   $editable    Whether an editor is offered.
     * @param   ?array<string, mixed>  $provenance  Conversion evidence, or null.
     *
     * @return  FieldPresentation  The constructed model.
     *
     * @since   2.0.0
     */
    private static function presentation(
        FieldWidget $widget,
        string $display,
        mixed $inputValue,
        bool $editable,
        ?array $provenance,
    ): FieldPresentation {
        return new FieldPresentation(
            'total',
            'Total',
            FieldPresentationContext::Detail,
            $widget,
            $display,
            $inputValue,
            $editable,
            false,
            [],
            [],
            [],
            $provenance,
        );
    }

    /**
     * Build the converted figure these assertions are made against.
     *
     * @return  ConvertedMoneyValue  25000.00 ZAR presented as EUR from a package-supplied rate.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedMoneyValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
                'EUR',
                $asAt,
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimalArithmetic::fromLiteral('0.04938240'),
                $asAt,
                'acme.rates.ecb',
            ),
        );
    }
}
