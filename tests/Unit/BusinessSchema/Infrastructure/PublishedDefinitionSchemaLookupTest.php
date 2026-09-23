<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Infrastructure;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\PublishedDefinitionSchemaLookup;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Host adapter answering the package compiler's target lookup from the App definition catalog.
 *
 * The package compiler resolves relationship and reference targets through its `DefinitionSchemaLookup`
 * port with a bare site identifier; what the host owes it is that the identifier, handle and pinned version
 * reach `BusinessDefinitionRepository::published()` unchanged and only a published definition comes back.
 *
 * @since  2.0.0
 */
#[CoversClass(PublishedDefinitionSchemaLookup::class)]
final class PublishedDefinitionSchemaLookupTest extends TestCase
{
    /**
     * The site, handle and pinned version pass through to the catalog and the published definition returns.
     *
     * A version the catalog never published, or a handle it does not serve, answers null rather than a
     * fallback, so the package compiler refuses the target instead of compiling against a guess.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedVersionIsResolvedThroughTheCatalogForTheRequestedSiteAndVersion(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::document())->published(1);
        $record = new DefinitionVersionRecord(
            $definition,
            new CompatibilityPlan(null, 1, null, $definition->checksum(), []),
            DefinitionStatus::Published,
            '00000000-0000-7000-8000-000000000001',
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $calls = [];
        $repository = $this->createStub(BusinessDefinitionRepository::class);
        $repository->method('published')->willReturnCallback(
            static function (
                SiteContext $site,
                string $identifier,
                ?int $version = null,
            ) use (
                $record,
                &$calls,
            ): ?DefinitionVersionRecord {
                $calls[] = [$site->identifier(), $identifier, $version];

                return $identifier === $record->definition->handle && $version === 1 ? $record : null;
            },
        );
        $lookup = new PublishedDefinitionSchemaLookup($repository);

        self::assertSame($definition, $lookup->published('default', $definition->handle, 1));
        self::assertNull($lookup->published('default', $definition->handle, 2));
        self::assertNull($lookup->published('default', 'site.default.absent'));
        self::assertSame([
            ['default', $definition->handle, 1],
            ['default', $definition->handle, 2],
            ['default', 'site.default.absent', null],
        ], $calls);
    }
}
