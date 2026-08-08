<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSchema\Domain;

use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalNameCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhysicalNameCompiler::class)]
final class PhysicalNameCompilerTest extends TestCase
{
    public function testNamesAreDeterministicBoundedAndDefinitionScoped(): void
    {
        $compiler = new PhysicalNameCompiler('kumwe_business_runtime_');
        $leftTable = $compiler->entityTable(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.very_long_sales_invoice_definition',
        );
        $rightTable = $compiler->entityTable(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'site.default.very_long_sales_invoice_definition',
        );
        $leftIndex = $compiler->index($leftTable, 'field.external_reference', ['c_external_reference']);
        $rightIndex = $compiler->index($rightTable, 'field.external_reference', ['c_external_reference']);

        self::assertSame($leftIndex, $compiler->index(
            $leftTable,
            'field.external_reference',
            ['c_external_reference'],
        ));
        self::assertNotSame(strtolower($leftTable), strtolower($rightTable));
        self::assertNotSame(strtolower($leftIndex), strtolower($rightIndex));
        self::assertLessThanOrEqual(63, strlen($leftTable));
        self::assertLessThanOrEqual(63, strlen($leftIndex));
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,62}$/D', $leftIndex);
    }

    public function testRejectsNonCanonicalPrefixesThatCouldCollapseOrProduceInvalidNames(): void
    {
        foreach (['tenant', 'tenant__', 'a__b_'] as $prefix) {
            try {
                new PhysicalNameCompiler($prefix);
                self::fail(sprintf('Prefix "%s" should be rejected.', $prefix));
            } catch (InvalidBusinessSchema $exception) {
                self::assertStringContainsString('prefix is invalid', $exception->getMessage());
            }
        }
    }

    public function testDistinctCanonicalPrefixesCannotCompileTheSamePhysicalName(): void
    {
        $definitionId = '018f4f24-98d8-7ad4-8f3f-38c909178b6b';

        self::assertNotSame(
            (new PhysicalNameCompiler('tenant_a_'))->entityTable($definitionId, 'site.default.asset'),
            (new PhysicalNameCompiler('tenant_b_'))->entityTable($definitionId, 'site.default.asset'),
        );
    }
}
