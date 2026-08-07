<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityClassification;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain\EntityTypeDefinitionTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDefinitionCompatibilityAnalyzer::class)]
final class BusinessDefinitionCompatibilityAnalyzerTest extends TestCase
{
    public function testFirstPublicationIsAdditiveAndDeterministic(): void
    {
        $draft = EntityTypeDefinition::fromArray(EntityTypeDefinitionTest::document());
        $plan = (new BusinessDefinitionCompatibilityAnalyzer())->analyze(null, $draft);

        self::assertSame(1, $plan->toVersion);
        self::assertFalse($plan->requiresConfirmation());
        self::assertSame(CompatibilityClassification::Additive, $plan->changes()[0]->classification);
        self::assertSame($draft->published(1)->checksum(), $plan->toChecksum);
    }

    public function testRequiredFieldWithoutDefaultRequiresDataMigration(): void
    {
        $document = EntityTypeDefinitionTest::document();
        $before = EntityTypeDefinition::fromArray($document)->published(1);
        $document['fields'][] = [
            'handle' => 'code',
            'label' => 'Code',
            'type' => 'core.text',
            'required' => true,
            'nullable' => false,
            'length' => 80,
        ];
        $draft = EntityTypeDefinition::fromArray($document);
        $plan = (new BusinessDefinitionCompatibilityAnalyzer())->analyze($before, $draft);

        self::assertTrue($plan->requiresConfirmation());
        self::assertContains(
            CompatibilityClassification::DataMigrationRequired,
            array_map(static fn ($change) => $change->classification, $plan->changes()),
        );
    }
}
