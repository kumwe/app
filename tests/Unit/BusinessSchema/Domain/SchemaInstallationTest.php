<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaInstallation::class)]
final class SchemaInstallationTest extends TestCase
{
    public function testExtensionPackageOwnerIdentityIsPreserved(): void
    {
        $definition = '018f4f24-98d8-7ad4-8f3f-38c909178b6b';
        $definitionChecksum = str_repeat('a', 64);
        $id = new PhysicalColumnBlueprint('record_id', 'c_record_id_12345678901234567890', 'guid');
        $table = new PhysicalTableBlueprint(
            'record',
            'kb_e_record_12345678901234567890',
            PhysicalTableKind::Entity,
            [$id],
            [$id->physicalName],
        );
        $blueprint = new PhysicalSchemaBlueprint($definition, 1, $definitionChecksum, [$table]);
        $at = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $installation = new SchemaInstallation(
            $definition,
            'default',
            'vendor/package',
            1,
            $definitionChecksum,
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $at,
            $at,
        );

        self::assertSame('vendor/package', $installation->ownerIdentifier);
        self::assertSame($installation->toArray(), SchemaInstallation::fromArray($installation->toArray())->toArray());
    }
}
