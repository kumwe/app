<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessCollectionPresentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises policy-safe generated collection presentation controls.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessCollectionPresentation::class)]
final class BusinessCollectionPresentationTest extends TestCase
{
    /**
     * Proves disclosed default columns and explicit density remain stable across pagination.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsDisclosedColumnsAndProducesCanonicalQueryState(): void
    {
        $presentation = BusinessCollectionPresentation::fromQuery([
            'columns' => ['reference', 'risk_score'],
            'density' => 'compact',
            'representation' => 'cards',
        ], $this->fields());

        self::assertSame(['reference', 'risk_score'], $presentation->columns);
        self::assertSame('compact', $presentation->density);
        self::assertSame('cards', $presentation->representation);
        self::assertSame([
            'columns' => ['reference', 'risk_score'],
            'density' => 'compact',
            'representation' => 'cards',
        ], $presentation->query());
    }

    /**
     * Proves a declared list view can provide defaults without widening current field policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUsesDeclaredDefaultsOnlyWhenNoNativeColumnsWereSubmitted(): void
    {
        $presentation = BusinessCollectionPresentation::fromQuery(
            [],
            $this->fields(),
            ['risk_score', 'reference'],
        );

        self::assertSame(['risk_score', 'reference'], $presentation->columns);
        self::assertSame('comfortable', $presentation->density);
        self::assertSame('auto', $presentation->representation);
    }

    /**
     * Proves policy changes remove withheld defaults without revealing them or breaking the collection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFiltersWithheldDefaultColumnsAndFallsBackToVisibleFields(): void
    {
        $filtered = BusinessCollectionPresentation::fromQuery(
            [],
            $this->fields(),
            ['secret', 'reference'],
        );
        self::assertSame(['reference'], $filtered->columns);

        $fallback = BusinessCollectionPresentation::fromQuery(
            [],
            $this->fields(),
            ['secret'],
        );
        self::assertSame(['reference', 'risk_score'], $fallback->columns);
    }

    /**
     * Proves withheld and duplicate field handles cannot enter the rendered column set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsWithheldAndDuplicateColumns(): void
    {
        foreach ([['secret'], ['reference', 'reference']] as $columns) {
            try {
                BusinessCollectionPresentation::fromQuery(['columns' => $columns], $this->fields());
                self::fail('Unavailable and duplicate columns must fail closed.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('unavailable, invalid, or duplicated', $exception->getMessage());
            }
        }
    }

    /**
     * Proves representation and density accept only the KIS collection vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnsupportedPresentationLiterals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('density control is unsupported');

        BusinessCollectionPresentation::fromQuery(['density' => 'extension-css'], $this->fields());
    }

    /**
     * Return the complete field catalogue visible to the simulated actor.
     *
     * @return  list<array<string, mixed>>  Policy-filtered metadata used by each test.
     *
     * @since   2.0.0
     */
    private function fields(): array
    {
        return [
            ['handle' => 'reference', 'label' => 'Reference'],
            ['handle' => 'risk_score', 'label' => 'Risk score'],
        ];
    }
}
