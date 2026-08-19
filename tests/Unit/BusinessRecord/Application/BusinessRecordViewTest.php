<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\RecordExpressionValues;
use Kumwe\App\BusinessRecord\Application\RecordFieldVisibility;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\Tests\Unit\BusinessDefinition\Domain\EntityTypeDefinitionTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordView::class)]
#[CoversClass(RecordExpressionValues::class)]
#[CoversClass(RecordFieldVisibility::class)]
/**
 * Proves application record views enforce conditional disclosure and projection bounds.
 *
 * @since  2.0.0
 */
final class BusinessRecordViewTest extends TestCase
{
    /**
     * Proves field visibility reads dependencies from the complete stored record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadDisclosureEvaluatesVisibilityAgainstTheWholeStoredRecord(): void
    {
        $definition = self::conditionalDefinition();
        $hidden = self::record(false, true);
        $visible = self::record(true, true);

        self::assertArrayNotHasKey('conditional_note', BusinessRecordView::fromRecord(
            $hidden,
            definition: $definition,
            resolvedValues: [
                'id' => $hidden->recordId,
                'name' => 'Asset',
                'enabled' => false,
                'conditional_note' => 'Must remain hidden',
            ],
        )->values);
        self::assertSame(
            'Visible note',
            BusinessRecordView::fromRecord($visible, definition: $definition)->values['conditional_note'],
        );
    }

    /**
     * Proves a missing visibility dependency withholds the conditional field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadDisclosureFailsClosedWhenAVisibilityDependencyIsUnavailable(): void
    {
        $definition = self::conditionalDefinition();
        $record = self::record(false, false);

        self::assertArrayNotHasKey(
            'conditional_note',
            BusinessRecordView::fromRecord($record, definition: $definition)->values,
        );
    }

    /**
     * Proves relation includes cannot exceed the application projection bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipIncludesAreBoundedAtTheApplicationProjectionBoundary(): void
    {
        $view = BusinessRecordView::fromRecord(
            self::record(true, true),
            definition: self::conditionalDefinition(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $view->withIncludes(array_fill_keys(['one', 'two', 'three', 'four', 'five'], []));
    }

    /**
     * Build a published definition with a conditional field and boolean dependency.
     *
     * @return  EntityTypeDefinition  Definition used for visibility evaluation.
     *
     * @since   2.0.0
     */
    private static function conditionalDefinition(): EntityTypeDefinition
    {
        $document = EntityTypeDefinitionTest::document();
        $document['fields'][] = [
            'handle' => 'enabled',
            'label' => 'Enabled',
            'type' => 'core.boolean',
            'required' => true,
            'nullable' => false,
            'default' => false,
        ];
        $document['fields'][] = [
            'handle' => 'conditional_note',
            'label' => 'Conditional note',
            'type' => 'core.text',
            'default' => 'Default note',
            'visibility_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'boolean', 'field' => 'enabled'],
                    ['op' => 'literal', 'type' => 'boolean', 'value' => true],
                ],
            ],
        ];

        return EntityTypeDefinition::fromArray($document)->published(1);
    }

    /**
     * Build one stored record with optional visibility dependency evidence.
     *
     * @param   bool  $enabled            Whether the conditional field should be visible.
     * @param   bool  $includeDependency  Whether the boolean dependency is available.
     *
     * @return  BusinessRecord  Stored record fixture.
     *
     * @since   2.0.0
     */
    private static function record(bool $enabled, bool $includeDependency): BusinessRecord
    {
        $values = [
            'id' => '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'name' => 'Asset',
            'normalized_name' => 'Asset',
            'conditional_note' => $enabled ? 'Visible note' : 'Must remain hidden',
        ];
        if ($includeDependency) {
            $values['enabled'] = $enabled;
        }

        return new BusinessRecord(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            1,
            '018f4f24-98d8-7ad4-8f3f-38c909178b6d',
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            RecordScope::reconstitute(ScopeMode::Site, 'default', null),
            1,
            null,
            $values,
            'user:test',
            new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
            'user:test',
            new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
        );
    }
}
