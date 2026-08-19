<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessBrowserQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessBrowserQuery::class)]
/**
 * Exercises bounded native browser query mapping and opaque cursor preservation.
 *
 * @since  2.0.0
 */
final class BusinessBrowserQueryTest extends TestCase
{
    /**
     * Proves typed native controls map into the shared query and survive pagination.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMapsNativeControlsIntoTheSharedQueryAndPreservesThemInTheNextCursor(): void
    {
        $query = BusinessBrowserQuery::fromQuery([
            'filters' => ['status' => 'ready', 'region' => 'north'],
            'integer_filters' => ['priority' => '0'],
            'boolean_filters' => ['enabled' => 'false'],
            'sort_field' => 'name',
            'sort_direction' => 'desc',
            'search_term' => 'priority',
            'search_fields' => ['name', 'reference'],
            'page_size' => '25',
            'include_archived' => '1',
        ]);

        self::assertSame('all', $query->document()['filter']['operator']);
        self::assertSame('ready', $query->formState()['filters']['status']);
        self::assertSame(0, $query->formState()['filters']['priority']);
        self::assertFalse($query->formState()['filters']['enabled']);
        self::assertSame('desc', $query->document()['sorts'][0]['direction']);
        self::assertSame(['name', 'reference'], $query->document()['search']['fields']);
        self::assertTrue($query->document()['include_archived']);

        $next = json_decode($query->next('opaque-cursor-value'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('opaque-cursor-value', $next['after']);
        self::assertSame($query->document()['filter'], $next['filter']);
        self::assertSame($query->document()['search'], $next['search']);
        self::assertSame($query->document()['sorts'], $next['sorts']);
    }

    /**
     * Proves an opaque query document retains its cursor and reconstructs safe controls.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAcceptsTheStableOpaqueDocumentAndRecoversSimpleGraphicalState(): void
    {
        $query = BusinessBrowserQuery::fromQuery(['query' => json_encode([
            'filter' => [
                'type' => 'comparison',
                'field' => 'status',
                'operator' => 'eq',
                'value' => 'draft',
            ],
            'sorts' => [['field' => 'name', 'direction' => 'asc']],
            'page_size' => 10,
            'after' => 'first-cursor',
        ], JSON_THROW_ON_ERROR)]);

        self::assertSame('first-cursor', $query->document()['after']);
        self::assertSame(['status' => 'draft'], $query->formState()['filters']);
        self::assertSame('name', $query->formState()['sort_field']);
        self::assertSame(10, $query->formState()['page_size']);
        self::assertSame('second-cursor', json_decode(
            $query->next('second-cursor'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        )['after']);
    }

    /**
     * Proves callers cannot combine an opaque document with separate graphical controls.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsMixedOpaqueAndGraphicalControls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be mixed');

        BusinessBrowserQuery::fromQuery([
            'query' => '{}',
            'sort_field' => 'name',
            'sort_direction' => 'asc',
        ]);
    }

    /**
     * Proves filter handles are unique across typed controls and booleans remain strict.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsDuplicateOrMalformedTypedFilters(): void
    {
        try {
            BusinessBrowserQuery::fromQuery([
                'filters' => ['status' => 'ready'],
                'boolean_filters' => ['status' => 'true'],
            ]);
            self::fail('A field cannot be supplied through two graphical filter types.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('duplicated', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('boolean filter');
        BusinessBrowserQuery::fromQuery(['boolean_filters' => ['enabled' => '1']]);
    }

    /**
     * Proves graphical filter and search collections enforce their hard bounds.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsUnboundedFiltersAndSearchFields(): void
    {
        try {
            BusinessBrowserQuery::fromQuery(['filters' => array_fill_keys(
                array_map(static fn (int $index): string => 'field_' . $index, range(1, 17)),
                'value',
            )]);
            self::fail('More than 16 graphical filters must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('more than 16', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('search field list');
        BusinessBrowserQuery::fromQuery([
            'search_term' => 'priority',
            'search_fields' => array_fill(0, 17, 'name'),
        ]);
    }
}
