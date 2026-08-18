<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Delivery\Browser;

use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use InvalidArgumentException;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessStructuredFieldForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessStructuredFieldForm::class)]
/**
 * Exercises bounded graphical open-object and ordered-row form mapping.
 *
 * @since  2.0.0
 */
final class BusinessStructuredFieldFormTest extends TestCase
{
    /**
     * Proves nested typed rows decode without a JSON text representation.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testDecodesNestedKeyValueAndRowControls(): void
    {
        $form = BusinessStructuredFieldForm::fromInput(
            InterfaceTranslation::translator(),
            'object',
            'structured[metadata]',
            [
                'kind' => 'object',
                'count' => '2',
                'entries' => [
                    [
                        'key' => 'priority',
                        'node' => ['kind' => 'integer', 'value' => '7'],
                    ],
                    [
                        'key' => 'flags',
                        'node' => [
                            'kind' => 'array',
                            'count' => '2',
                            'entries' => [
                                ['node' => ['kind' => 'boolean', 'value' => '1']],
                                ['node' => ['kind' => 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            null,
            128,
            true,
        );

        self::assertSame(['priority' => 7, 'flags' => [true, null]], $form->value);
        self::assertSame('object', $form->model['kind']);
        self::assertSame('array', $form->model['entries'][1]['node']['kind']);
    }

    /**
     * Proves a configuration render can expand rows while retaining existing native values.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testConfiguresBoundedRowsWithoutSubmittingAValue(): void
    {
        $form = BusinessStructuredFieldForm::fromInput(
            InterfaceTranslation::translator(),
            'array',
            'structured[items]',
            [
                'kind' => 'array',
                'count' => '3',
                'entries' => [
                    ['node' => ['kind' => 'string', 'value' => 'north']],
                ],
            ],
            null,
            4,
            false,
        );

        self::assertFalse($form->submitted);
        self::assertNull($form->value);
        self::assertCount(3, $form->model['entries']);
        self::assertSame('north', $form->model['entries'][0]['node']['value']);
    }

    /**
     * Proves duplicate keys, floating-point authoring, and excessive row counts fail closed.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsAmbiguousOrUnboundedStructuredControls(): void
    {
        foreach (
            [
                [
                    'kind' => 'object',
                    'count' => '2',
                    'entries' => [
                        ['key' => 'same', 'node' => ['kind' => 'string', 'value' => 'a']],
                        ['key' => 'same', 'node' => ['kind' => 'string', 'value' => 'b']],
                    ],
                ],
                [
                    'kind' => 'object',
                    'count' => '1',
                    'entries' => [
                        ['key' => 'ratio', 'node' => ['kind' => 'decimal', 'value' => '1.2']],
                    ],
                ],
                ['kind' => 'object', 'count' => '129', 'entries' => []],
            ] as $controls
        ) {
            try {
                BusinessStructuredFieldForm::fromInput(
                    InterfaceTranslation::translator(),
                    'object',
                    'structured[metadata]',
                    $controls,
                    null,
                    128,
                    true,
                );
                self::fail('An ambiguous or unbounded structured control document was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Proves a retained list cannot silently become an object, or an object become a list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnInitialValueWithTheWrongFixedRootType(): void
    {
        foreach (
            [
            ['object', ['north']],
            ['array', ['region' => 'north']],
            ] as [$root, $initial]
        ) {
            try {
                BusinessStructuredFieldForm::fromInput(
                    InterfaceTranslation::translator(),
                    $root,
                    'structured[metadata]',
                    null,
                    $initial,
                    128,
                    false,
                );
                self::fail('A structured initial value with the wrong root type was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
