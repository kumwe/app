<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(SchemaEvolutionHints::class)]
final class NeutralBusinessFixtureTest extends TestCase
{
    public function testStandaloneBackupDefinitionIsStableAndValid(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);

        self::assertSame(NeutralBusinessFixture::DEFINITION_ID, $definition->id);
        self::assertSame(NeutralBusinessFixture::HANDLE, $definition->handle);
        self::assertTrue($definition->softDeleteEnabled);
        self::assertNotNull($definition->workflow);
        self::assertSame(
            $definition->checksum(),
            EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument())->checksum(),
        );
    }

    public function testRelationGraphCoversEveryCardinalityAndOrderedOwnedLines(): void
    {
        $suffix = 'fixture';
        $target = EntityTypeDefinition::fromArray(NeutralBusinessFixture::relationTargetDocument(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e30',
        ));
        $line = EntityTypeDefinition::fromArray(NeutralBusinessFixture::ownedLineDocument(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e31',
        ));
        $owner = EntityTypeDefinition::fromArray(NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e32',
            $target->handle,
            $line->handle,
        ));
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$target, $line, $owner]);

        self::assertSame(
            ['one_to_one', 'many_to_one', 'one_to_many', 'many_to_many', 'owned_line_collection'],
            array_map(
                static fn (RelationshipDefinition $relationship): string => $relationship->kind->value,
                $owner->relationships(),
            ),
        );
        self::assertTrue($owner->relationships()[2]->ordered);
        self::assertTrue($owner->relationships()[3]->ordered);
        self::assertTrue($owner->relationships()[4]->ordered);
        $fieldLines = $owner->runtimeRelationship('field_lines');
        self::assertNotNull($fieldLines);
        self::assertSame('owned_line_collection', $fieldLines->kind->value);
        self::assertSame($line->handle, $fieldLines->target);
        self::assertTrue($fieldLines->ordered);
        self::assertSame('cascade', $fieldLines->onDelete->value);
    }

    public function testEvolutionReferenceAndInverseDocumentsAreTypedValidContracts(): void
    {
        $suffix = 'typed';
        $definitionId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e33';
        $v1 = EntityTypeDefinition::fromArray(NeutralBusinessFixture::document($suffix, $definitionId));
        $v2 = EntityTypeDefinition::fromArray(NeutralBusinessFixture::evolutionDocument(
            $suffix,
            $definitionId,
        ));
        $validator = new BusinessDefinitionValidator(new FieldTypeRegistry());
        $validator->validateGraph([$v1]);
        $validator->validateGraph([$v2]);
        $hints = SchemaEvolutionHints::fromDefinition($v2);

        self::assertSame(['status' => 'lifecycle_status'], $hints->renameForTable('record'));
        $backfill = $hints->backfill('evolution_code');
        self::assertInstanceOf(Expression::class, $backfill);
        self::assertSame('name', $backfill->toArray()['field'] ?? null);
        self::assertSame(2, $hints->repin($v2->handle));
        self::assertSame('VERSION-TWO', NeutralBusinessFixture::evolutionRecordValues(
            evolutionCode: 'VERSION-TWO',
        )['evolution_code']);

        $target = EntityTypeDefinition::fromArray(NeutralBusinessFixture::referenceTargetDocument(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e34',
        ));
        $owner = EntityTypeDefinition::fromArray(NeutralBusinessFixture::entityReferenceOwnerDocument(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e35',
            $target->handle,
        ));
        $validator->validateGraph([$target, $owner]);
        self::assertSame(IdentityStrategy::Reference, $target->identityStrategy);
        self::assertSame('core.entity_reference', $owner->fields()[2]->type);

        $inverse = NeutralBusinessFixture::inverseRelationshipDocuments(
            $suffix,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e36',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e37',
            'testing/neutral_typed',
        );
        $left = EntityTypeDefinition::fromArray($inverse['left']);
        $right = EntityTypeDefinition::fromArray($inverse['right']);
        $validator->validateGraph([$left, $right]);
        self::assertSame('lefts', $left->relationships()[0]->inverse);
        self::assertSame('rights', $right->relationships()[0]->inverse);
    }
}
