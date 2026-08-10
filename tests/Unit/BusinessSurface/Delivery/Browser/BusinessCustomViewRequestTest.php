<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessCustomViewPresenter;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessCustomViewRequest;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessSchemaForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessCustomViewPresenter::class)]
#[CoversClass(BusinessCustomViewRequest::class)]
#[CoversClass(BusinessSchemaForm::class)]
/**
 * Exercises native custom-view parameter admission and safe generic result projection.
 *
 * @since  2.0.0
 */
final class BusinessCustomViewRequestTest extends TestCase
{
    /**
     * Proves schema-derived scalar controls produce typed parameters and a declared record query.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsSchemaDrivenParametersAndDeclaredRecordControls(): void
    {
        $initial = BusinessCustomViewRequest::fromQuery([], self::view(), self::fields(), self::schema());
        $term = $initial->fields[0]['options'][0]['value'];
        $request = BusinessCustomViewRequest::fromQuery([
            'run' => '1',
            'parameters' => ['term' => $term, 'minimum' => '7'],
            'filters' => ['status' => 'ready'],
            'sort_field' => 'name',
            'sort_direction' => 'asc',
            'search_term' => 'north',
            'search_fields' => ['name'],
            'page_size' => '25',
        ], self::view(), self::fields(), self::schema());

        self::assertTrue($request->submitted);
        self::assertSame(['term' => 'north', 'minimum' => 7], $request->parameters);
        self::assertSame('ready', $request->records->document()['filter']['value']);
        self::assertSame('name', $request->records->document()['sorts'][0]['field']);
        self::assertSame('north', $request->records->document()['search']['term']);
    }

    /**
     * Proves raw JSON and fields outside the policy-visible declaration fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsRawJsonAndUndeclaredGraphicalFields(): void
    {
        foreach (
            [
            ['query' => '{}'],
            ['filters' => ['secret' => 'value']],
            ['sort_field' => 'secret', 'sort_direction' => 'asc'],
            ['search_term' => 'x', 'search_fields' => ['status']],
            ] as $query
        ) {
            try {
                BusinessCustomViewRequest::fromQuery($query, self::view(), self::fields(), self::schema());
                self::fail('An opaque or undeclared custom-view query was accepted.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    /**
     * Proves complex required parameters are identified without exposing a raw authoring fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsNestedObjectsAndConfigurableBoundedArrayRows(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'criteria' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'rows' => [
                            'type' => 'array',
                            'maxItems' => 2,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'code' => ['type' => 'string', 'maxLength' => 10],
                                ],
                                'required' => ['code'],
                            ],
                        ],
                    ],
                    'required' => ['rows'],
                ],
            ],
            'required' => ['criteria'],
        ];
        $initial = BusinessCustomViewRequest::fromQuery([], self::view(), self::fields(), $schema);
        $rows = $initial->fields[0]['children'][0];
        $configured = BusinessCustomViewRequest::fromQuery([
            'configure' => '1',
            'schema_counts' => [$rows['path_token'] => '2'],
        ], self::view(), self::fields(), $schema);
        self::assertCount(2, $configured->fields[0]['children'][0]['items']);

        $request = BusinessCustomViewRequest::fromQuery([
            'run' => '1',
            'schema_counts' => [$rows['path_token'] => '2'],
            'parameters' => [
                'criteria' => ['rows' => [['code' => 'north'], ['code' => 'south']]],
            ],
        ], self::view(), self::fields(), $schema);

        self::assertSame([
            'criteria' => ['rows' => [['code' => 'north'], ['code' => 'south']]],
        ], $request->parameters);
    }

    /**
     * Proves nested contract data becomes semantic nodes without JSON serialization or markup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresentsNestedExactDataAsCoreSemanticNodes(): void
    {
        $projection = (new BusinessCustomViewPresenter())->present([
            'title' => 'Northern summary',
            'items' => [['label' => 'Windhoek', 'ready' => true]],
            'count' => 1,
        ]);

        self::assertSame('object', $projection['kind']);
        self::assertSame('Northern summary', $projection['entries'][0]['value']['value']);
        self::assertSame('list', $projection['entries'][1]['value']['kind']);
        self::assertSame(
            'Yes',
            $projection['entries'][1]['value']['items'][0]['value']['entries'][1]['value']['value'],
        );
        self::assertSame('1', $projection['entries'][2]['value']['value']);
    }

    /**
     * Proves exact null properties use a graphical constant and preserve optional presence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsRequiredAndOptionalNullProperties(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'required_null' => ['type' => 'null'],
                'optional_null' => ['type' => 'null'],
            ],
            'required' => ['required_null'],
        ];
        $initial = BusinessSchemaForm::fromInput($schema, 'input');
        $optional = $initial->fields[1];
        self::assertSame('const', $optional['kind']);

        $submitted = BusinessSchemaForm::fromInput(
            $schema,
            'input',
            [],
            [],
            [$optional['path_token'] => '1'],
            true,
        );

        self::assertSame(['required_null' => null, 'optional_null' => null], $submitted->value);
    }

    /**
     * Build policy-visible view metadata for native-control tests.
     *
     * @return  array<string, mixed>  List-view projection and allowed query fields.
     *
     * @since   2.0.0
     */
    private static function view(): array
    {
        return [
            'handle' => 'summary',
            'label' => 'Summary',
            'kind' => 'list',
            'fields' => ['name', 'status'],
            'filters' => ['status'],
            'sorts' => ['name'],
        ];
    }

    /**
     * Build policy-filtered definition fields for native-control tests.
     *
     * @return  list<array<string, mixed>>  Search disclosure metadata.
     *
     * @since   2.0.0
     */
    private static function fields(): array
    {
        return [
            ['handle' => 'name', 'uses' => ['search' => true]],
            ['handle' => 'status', 'uses' => ['search' => false]],
        ];
    }

    /**
     * Build one closed scalar parameter schema.
     *
     * @return  array<string, mixed>  Enum and integer properties.
     *
     * @since   2.0.0
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'term' => [
                    'type' => 'string',
                    'title' => 'Region',
                    'enum' => ['north', 'south'],
                    'maxLength' => 5,
                ],
                'minimum' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
            'required' => ['term', 'minimum'],
        ];
    }
}
