<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Infrastructure;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence\DoctrinePersistedFieldTypeDefinitionResolver;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePersistedFieldTypeDefinitionResolver::class)]
final class DoctrinePersistedFieldTypeDefinitionResolverTest extends TestCase
{
    public function testInactivePersistedTypeRemainsStructurallyResolvableWithoutRuntimeRegistration(): void
    {
        $definition = self::definition();
        $database = $this->database();
        $database->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'WHERE identifier = ?') && !str_contains($sql, 'active')), [$definition->id])
            ->willReturn(self::row($definition));
        $active = new FieldTypeRegistry();

        $resolved = (new DoctrinePersistedFieldTypeDefinitionResolver(
            $database,
            new TableNames($database, 'kumwe_'),
            $active,
        ))->get($definition->id);

        self::assertSame($definition->toArray(), $resolved->toArray());
        self::assertFalse($active->has($definition->id));
    }

    public function testCoreRuntimeTypeIsResolvedWithoutAHistoryLookup(): void
    {
        $database = $this->database();
        $database->expects(self::never())->method('fetchAssociative');
        $active = new FieldTypeRegistry();

        $resolved = (new DoctrinePersistedFieldTypeDefinitionResolver(
            $database,
            new TableNames($database, 'kumwe_'),
            $active,
        ))->get('core.text');

        self::assertSame('core.text', $resolved->id);
    }

    public function testPersistedMetadataRemainsAuthoritativeOverAnActiveContribution(): void
    {
        $definition = self::definition();
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            ...self::row($definition),
            'checksum' => str_repeat('0', 64),
        ]);
        $active = new FieldTypeRegistry();
        $active->register(DefinitionOwner::extension('testing/inactive'), $definition);

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('checksum');
        (new DoctrinePersistedFieldTypeDefinitionResolver(
            $database,
            new TableNames($database, 'kumwe_'),
            $active,
        ))->get($definition->id);
    }

    public function testPersistedTypeChecksumDriftFailsClosed(): void
    {
        $definition = self::definition();
        $database = $this->database();
        $database->method('fetchAssociative')->willReturn([
            ...self::row($definition),
            'checksum' => str_repeat('0', 64),
        ]);

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('checksum');
        (new DoctrinePersistedFieldTypeDefinitionResolver(
            $database,
            new TableNames($database, 'kumwe_'),
            new FieldTypeRegistry(),
        ))->get($definition->id);
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    private static function definition(): FieldTypeDefinition
    {
        return new FieldTypeDefinition(
            'testing.inactive.value',
            'Inactive value',
            'Immutable historical field-type metadata.',
            'string',
            'string',
            ['options'],
        );
    }

    /** @return array<string, mixed> */
    private static function row(FieldTypeDefinition $definition): array
    {
        return [
            'identifier' => $definition->id,
            'owner_type' => 'extension',
            'owner_identifier' => 'testing/inactive',
            'checksum' => CanonicalDefinitionJson::checksum($definition->toArray()),
            'canonical_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ];
    }
}
