<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversNothing]
final class BusinessRecordMutationGenerationIntegrationTest extends TestCase
{
    public function testStaleResolutionRejectsActiveInstallingActiveGenerationAbaAcrossKernels(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $primaryContext = TestKernelFactory::administratorContext($primary);
        $secondaryContext = TestKernelFactory::administratorContext($secondary);
        $primaryDatabase = $primary->get(Connection::class);
        $secondaryDatabase = $secondary->get(Connection::class);
        $tables = $primary->get(TableNames::class);
        $fence = $primary->get(BusinessRecordMutationFence::class);
        $primaryResolver = $primary->get(BusinessRecordDefinitionResolver::class);
        $secondaryResolver = $secondary->get(BusinessRecordDefinitionResolver::class);
        $definitions = $secondary->get(BusinessDefinitionService::class);
        $schemas = $primary->get(BusinessSchemaService::class);
        $compiler = $secondary->get(DefinitionPhysicalSchemaCompiler::class);
        self::assertInstanceOf(Connection::class, $primaryDatabase);
        self::assertInstanceOf(Connection::class, $secondaryDatabase);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(BusinessRecordMutationFence::class, $fence);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $primaryResolver);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $secondaryResolver);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(DefinitionPhysicalSchemaCompiler::class, $compiler);

        $suffix = self::suffix();
        $definitionId = Uuid::uuid7()->toString();
        $v1 = NeutralBusinessFixture::install(
            $primary,
            $primaryContext,
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        $source = $schemas->installation($primaryContext, $definitionId);
        self::assertNotNull($source);

        try {
            $primaryDatabase->beginTransaction();
            $staleGeneration = $fence->lock($primaryContext, $v1->handle);
            $resolvedV1 = $primaryResolver->forCreate($primaryContext, $v1->handle);
            $staleGeneration->assertMatches($resolvedV1);
            $primaryDatabase->commit();

            $currentDraft = $definitions->draft($secondaryContext, $definitionId);
            $saved = $definitions->saveDraft(
                $secondaryContext,
                EntityTypeDefinition::fromArray(
                    NeutralBusinessFixture::evolutionDocument($suffix, $definitionId),
                ),
                $currentDraft->revision,
            );
            $v2 = $definitions->publish(
                $secondaryContext,
                $definitionId,
                $saved->revision,
                true,
            )->definition;
            $target = $compiler->compile($v2, $secondaryContext->site());

            $secondaryDatabase->update(
                $tables->raw('business_schema_installations'),
                ['status' => SchemaInstallationStatus::Installing->value],
                ['definition_id' => $definitionId],
            );
            try {
                $secondaryResolver->forCreate($secondaryContext, $v1->handle);
                self::fail('An installing generation must be unavailable to record commands.');
            } catch (BusinessRecordSchemaUnavailable) {
                self::assertTrue(true);
            }

            $secondaryDatabase->update(
                $tables->raw('business_schema_installations'),
                [
                    'definition_version' => $v2->definitionVersion,
                    'definition_checksum' => $v2->checksum(),
                    'schema_checksum' => $target->checksum(),
                    'blueprint' => $target->toArray(),
                    'status' => SchemaInstallationStatus::Active->value,
                ],
                ['definition_id' => $definitionId],
                ['blueprint' => Types::JSON],
            );
            $resolvedV2 = $secondaryResolver->forCreate($secondaryContext, $v1->handle);
            try {
                $staleGeneration->assertMatches($resolvedV2);
                self::fail('An Active(v1) token must reject a later Active(v2) generation.');
            } catch (BusinessRecordTemporarilyUnavailable) {
                self::assertTrue(true);
            }

            $primaryDatabase->beginTransaction();
            $freshGeneration = $fence->lock($primaryContext, $v1->handle);
            $freshGeneration->assertMatches($resolvedV2);
            self::assertSame(2, $freshGeneration->definitionVersion);
            $primaryDatabase->rollBack();
        } finally {
            if ($primaryDatabase->isTransactionActive()) {
                $primaryDatabase->rollBack();
            }
            $secondaryDatabase->update(
                $tables->raw('business_schema_installations'),
                [
                    'owner_identifier' => $source->ownerIdentifier,
                    'definition_version' => $source->definitionVersion,
                    'definition_checksum' => $source->definitionChecksum,
                    'schema_checksum' => $source->schemaChecksum,
                    'blueprint' => $source->blueprint->toArray(),
                    'status' => SchemaInstallationStatus::Active->value,
                ],
                ['definition_id' => $definitionId],
                ['blueprint' => Types::JSON],
            );
        }
    }

    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
