<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(BuiltInFieldTypes::class)]
final class EntityTypeDefinitionTest extends TestCase
{
    public function testCanonicalDefinitionProvidesStableChecksumAndDependencyGraph(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::document());
        $copy = EntityTypeDefinition::fromArray(self::document());

        self::assertSame($definition->checksum(), $copy->checksum());
        self::assertSame([
            'fields' => ['id' => [], 'name' => [], 'normalized_name' => ['name']],
            'entities' => [],
            'field_types' => ['core.computed', 'core.text', 'core.uuid'],
        ], $definition->dependencyGraph());
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testComputedFieldRequiresServerOnlyReadOnlyFormula(): void
    {
        $document = self::document();
        $document['fields'][2]['server_only'] = false;

        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    public function testFormulaDependencyCyclesFailBeforePersistence(): void
    {
        $document = self::document();
        $document['fields'][1]['computed'] = true;
        $document['fields'][1]['server_only'] = true;
        $document['fields'][1]['read_only'] = true;
        $document['fields'][1]['formula'] = [
            'op' => 'field',
            'type' => 'string',
            'field' => 'normalized_name',
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cycle');
        EntityTypeDefinition::fromArray($document);
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        return [
            'id' => '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'name',
                    'label' => 'Name',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'searchable' => true,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'normalized_name',
                    'label' => 'Normalized name',
                    'type' => 'core.computed',
                    'nullable' => true,
                    'server_only' => true,
                    'computed' => true,
                    'read_only' => true,
                    'formula' => [
                        'op' => 'field',
                        'type' => 'string',
                        'field' => 'name',
                    ],
                ],
            ],
            'relationships' => [],
            'views' => [[
                'handle' => 'list',
                'label' => 'Assets',
                'kind' => 'list',
                'fields' => ['name'],
                'filters' => ['name'],
                'sorts' => ['name'],
                'administrator' => true,
                'portal' => false,
                'public' => false,
            ]],
            'actions' => [[
                'handle' => 'archive',
                'label' => 'Archive',
                'capability' => 'content.archive',
                'administrator' => true,
                'portal' => false,
                'public' => false,
            ]],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }
}
