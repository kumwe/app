<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Presentation;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldWidget;
use KumweExample\Announcements\Presentation\SeverityFieldPresenter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the shipped schema-3 example presenter remains markup-free, closed, and non-widening.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class SeverityFieldPresenterTest extends TestCase
{
    /**
     * Load the example-owned presenter before exercising its isolated contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4)
            . '/examples/extensions/announcements/src/Presentation/SeverityFieldPresenter.php';
    }

    /**
     * Edit contexts expose only the signed field's closed severity choices.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresentsClosedSeveritySelectorWhenEditingIsPermitted(): void
    {
        $presentation = (new SeverityFieldPresenter())->present(new FieldPresentationRequest(
            self::field(),
            self::type(),
            FieldPresentationContext::Create,
            'warning',
            editable: true,
        ));

        self::assertSame(FieldWidget::Select, $presentation->widget);
        self::assertTrue($presentation->editable);
        self::assertSame('Warning', $presentation->display);
        self::assertSame('warning', $presentation->inputValue);
        self::assertSame(
            [
                ['value' => 'info', 'label' => 'Info'],
                ['value' => 'notice', 'label' => 'Notice'],
                ['value' => 'warning', 'label' => 'Warning'],
                ['value' => 'critical', 'label' => 'Critical'],
            ],
            $presentation->options,
        );
    }

    /**
     * Read contexts expose escaped text and retain no input value or selector options.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresentsReadOnlySeverityWithoutRetainedInput(): void
    {
        $presentation = (new SeverityFieldPresenter())->present(new FieldPresentationRequest(
            self::field(),
            self::type(),
            FieldPresentationContext::Detail,
            'critical',
            editable: true,
        ));

        self::assertSame(FieldWidget::Output, $presentation->widget);
        self::assertFalse($presentation->editable);
        self::assertSame('Critical', $presentation->display);
        self::assertNull($presentation->inputValue);
        self::assertSame([], $presentation->options);
    }

    /**
     * A value outside the same closed set validated by every write adapter is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsSeverityOutsideDeclaredOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside its declared options');

        (new SeverityFieldPresenter())->present(new FieldPresentationRequest(
            self::field(),
            self::type(),
            FieldPresentationContext::Detail,
            'emergency',
        ));
    }

    /**
     * Build the example's declared custom field type.
     *
     * @return  FieldTypeDefinition  Bounded string-backed severity type.
     *
     * @since   2.0.0
     */
    private static function type(): FieldTypeDefinition
    {
        return new FieldTypeDefinition(
            'kumwe.announcements-example.severity',
            'Announcement severity',
            'A package-owned bounded severity value for announcements.',
            'string',
            'string',
            ['options'],
        );
    }

    /**
     * Build the example field carrying its signed closed option set.
     *
     * @return  FieldDefinition  Required severity field.
     *
     * @since   2.0.0
     */
    private static function field(): FieldDefinition
    {
        return new FieldDefinition(
            'severity',
            'Severity',
            'kumwe.announcements-example.severity',
            required: true,
            nullable: false,
            configuration: ['options' => ['info', 'notice', 'warning', 'critical']],
        );
    }
}
