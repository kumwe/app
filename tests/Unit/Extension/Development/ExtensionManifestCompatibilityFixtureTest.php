<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
/**
 * Locks the public parser contract for every supported extension manifest schema.
 *
 * @since  2.0.0
 */
final class ExtensionManifestCompatibilityFixtureTest extends TestCase
{
    #[DataProvider('supportedSchemas')]
    /**
     * Parse one committed compatibility fixture without migration or reinterpretation.
     *
     * @param   int     $schema  Expected schema version.
     * @param   string  $path    Fixture path relative to the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSupportedManifestSchemaRemainsCompatible(int $schema, string $path): void
    {
        $json = file_get_contents(dirname(__DIR__, 4) . '/' . $path);

        self::assertIsString($json);
        self::assertSame($schema, ExtensionManifest::fromJson($json)->schemaVersion());
    }

    /**
     * Enumerate the immutable manifest compatibility fixtures.
     *
     * @return  iterable<string, array{int, string}>  Schema number and repository-relative fixture path.
     *
     * @since   2.0.0
     */
    public static function supportedSchemas(): iterable
    {
        yield 'schema 1' => [1, 'tests/Fixtures/ExtensionApi/schema-1/kumwe.json'];
        yield 'schema 2' => [2, 'tests/Fixtures/ExtensionApi/schema-2/kumwe.json'];
        yield 'schema 3' => [3, 'tests/Fixtures/ExtensionApi/schema-3/kumwe.json'];
        yield 'schema 4' => [4, 'tests/Fixtures/ExtensionApi/schema-4/kumwe.json'];
    }

    /**
     * Pin schema 4 to contribution SPI 2 and its complete closed integration object.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaFourCanonicalContributionBytesRemainStable(): void
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/schema-4/kumwe.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            'd1410f96ea3b6d0036813384e03d4b91d72694d49ae49c87d098ff5602ec4402',
            hash('sha256', $json),
        );
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $contributions = $document['contributions'] ?? null;
        self::assertIsArray($contributions);
        $integration = $contributions['integration'] ?? null;
        self::assertIsArray($integration);
        self::assertSame([
            'event_schemas',
            'domain_listeners',
            'consumers',
            'jobs',
            'queues',
            'schedules',
            'projections',
            'reports',
            'webhooks',
        ], array_keys($integration));
        foreach ($integration as $kind => $definitions) {
            self::assertIsArray($definitions, sprintf('Schema-4 fixture kind %s is not a list.', $kind));
            self::assertCount(1, $definitions, sprintf('Schema-4 fixture kind %s is not protected.', $kind));
        }

        $manifest = ExtensionManifest::fromJson($json);

        self::assertSame(ManifestContributionSet::CURRENT_SPI_VERSION, $manifest->contributions()->spiVersion());
        self::assertSame($contributions, $manifest->contributions()->toArray());
    }
}
