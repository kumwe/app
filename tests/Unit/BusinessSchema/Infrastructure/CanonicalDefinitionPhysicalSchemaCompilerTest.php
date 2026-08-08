<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSchema\Infrastructure;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\CMS\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalDefinitionPhysicalSchemaCompiler::class)]
final class CanonicalDefinitionPhysicalSchemaCompilerTest extends TestCase
{
    public function testReferenceIdentityUsesGuidPrimaryKeyAndScopedAlternateUniqueIndex(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
            'reference',
        ));
        $blueprint = $this->compiler()->compile($definition, SiteContext::fromString('default'));
        $table = $blueprint->table('record');

        self::assertNotNull($table);
        self::assertSame('guid', $table->column('record_id')?->doctrineType);
        self::assertSame([$table->column('record_id')?->physicalName], $table->primaryKey);
        $identityIndex = array_values(array_filter(
            $table->indexes(),
            static fn ($index): bool => $index->logicalName === 'field.reference',
        ))[0] ?? null;
        self::assertNotNull($identityIndex);
        self::assertTrue($identityIndex->unique);
        self::assertSame([
            $table->column('site_identifier')?->physicalName,
            $table->column('reference')?->physicalName,
        ], $identityIndex->columns);
    }

    public function testConstraintNamesCannotCollideAcrossDefinitions(): void
    {
        $left = $this->compiler()->compile(EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        )), SiteContext::fromString('default'));
        $right = $this->compiler()->compile(EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'site.default.contact',
        )), SiteContext::fromString('default'));
        $leftNames = array_map(
            static fn ($index): string => strtolower($index->physicalName),
            $left->table('record')?->indexes() ?? []
        );
        $rightNames = array_map(
            static fn ($index): string => strtolower($index->physicalName),
            $right->table('record')?->indexes() ?? []
        );

        self::assertSame([], array_intersect($leftNames, $rightNames));
        foreach ([...$leftNames, ...$rightNames] as $name) {
            self::assertLessThanOrEqual(63, strlen($name));
        }
    }

    public function testVirtualFormulaIsOmittedAndStoredFormulaUsesItsExactResultType(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][] = self::formula('virtual_total', 'integer', 'virtual');
        $document['fields'][] = self::formula('stored_total', 'decimal', 'stored', 18, 4);
        $table = $this->compiler()->compile(
            EntityTypeDefinition::fromArray($document),
            SiteContext::fromString('default'),
        )->table('record');

        self::assertNotNull($table);
        self::assertNull($table->column('virtual_total'));
        self::assertSame('decimal', $table->column('stored_total')?->doctrineType);
        self::assertSame(18, $table->column('stored_total')?->options['precision'] ?? null);
        self::assertSame(4, $table->column('stored_total')?->options['scale'] ?? null);
    }

    public function testStructuredRuntimeDefaultIsNotEmittedAsANonPortableJsonDatabaseDefault(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][] = [
            'handle' => 'preferences',
            'label' => 'Preferences',
            'type' => 'core.bounded_json',
            'default' => ['layout' => 'compact'],
            'configuration' => ['max_bytes' => 1024],
        ];

        $column = $this->compiler()->compile(
            EntityTypeDefinition::fromArray($document),
            SiteContext::fromString('default'),
        )->table('record')?->column('preferences');

        self::assertNotNull($column);
        self::assertSame('json', $column->doctrineType);
        self::assertArrayNotHasKey('default', $column->options);
    }

    public function testPortableTextLengthBoundaryIsPreservedWithoutSilentCapping(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][1]['length'] = 1000;
        $document['fields'][1]['indexed'] = false;
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);

        $column = $this->compiler()->compile(
            $definition,
            SiteContext::fromString('default'),
        )->table('record')?->column('external_reference');

        self::assertSame(1000, $column?->options['length'] ?? null);
    }

    private function compiler(): CanonicalDefinitionPhysicalSchemaCompiler
    {
        return new CanonicalDefinitionPhysicalSchemaCompiler(
            $this->createStub(BusinessDefinitionRepository::class),
            new FieldTypeRegistry(),
            new PhysicalNameCompiler('kumwe_'),
        );
    }

    /** @return array<string, mixed> */
    private static function document(string $id, string $handle, string $identity = 'uuid'): array
    {
        $identityType = $identity === 'reference' ? 'core.reference_identity' : 'core.uuid';

        return [
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Record',
            'plural_label' => 'Records',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => $identity,
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => $identity === 'reference' ? 'reference' : 'id',
                    'label' => 'Identity',
                    'type' => $identityType,
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'external_reference',
                    'label' => 'External reference',
                    'type' => 'core.text',
                    'length' => 100,
                    'indexed' => true,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function formula(
        string $handle,
        string $type,
        string $mode,
        ?int $precision = null,
        ?int $scale = null,
    ): array {
        return [
            'handle' => $handle,
            'label' => $handle,
            'type' => 'core.computed',
            'nullable' => true,
            'server_only' => true,
            'computed' => true,
            'read_only' => true,
            'formula' => ['op' => 'literal', 'type' => $type, 'value' => $type === 'decimal' ? '1.2500' : 1],
            'computation_mode' => $mode,
            'precision' => $precision,
            'scale' => $scale,
        ];
    }
}
