<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(ExtensionManifest::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
/**
 * Holds the frozen extension contract to what the code actually offers a package.
 *
 * `tools/verify-extension-contract.php` checks the two contract documents against each other and against
 * the tree without loading anything, which is what lets it run before Composer. This test is the other
 * half: it loads the classes and asks whether the classification is still true — whether every promised
 * surface exists, whether the public surface is closed over the types it mentions, and whether the SPI
 * versions the documents name are the ones the code enforces.
 *
 * @since  2.0.0
 */
final class ExtensionContractFreezeTest extends TestCase
{
    /**
     * Require the contribution surfaces the contract lists to be exactly the ones the registries hold.
     *
     * An extension can only contribute to a surface that exists, so this list being complete is what
     * makes "the contract is frozen" a checkable statement rather than a claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheContractListsEveryContributionSurfaceTheRegistriesHold(): void
    {
        $declared = self::generations()['contribution_surfaces'];
        self::assertIsArray($declared);

        self::assertSame((new ExtensionContributionRegistrySet())->surfaceKeys(), $declared);
    }

    /**
     * Require every classified public type to exist, at its recorded path, as its recorded kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryClassifiedPublicTypeExistsAsClassified(): void
    {
        $types = self::classification()['types'];
        self::assertIsArray($types);
        self::assertNotSame([], $types);

        foreach ($types as $entry) {
            self::assertIsArray($entry);
            $type = $entry['type'];
            self::assertIsString($type);
            self::assertSame('public', $entry['visibility'] ?? null);
            $kind = match (true) {
                interface_exists($type) => 'interface',
                enum_exists($type) => 'enum',
                class_exists($type) => 'class',
                default => 'missing',
            };
            self::assertSame($kind, $entry['kind'] ?? null, sprintf('Public type %s is not as classified.', $type));
            $file = (new ReflectionClass($type))->getFileName();
            self::assertIsString($file);
            self::assertSame(
                $entry['path'] ?? null,
                substr($file, strlen(dirname(__DIR__, 4)) + 1),
                sprintf('Public type %s moved.', $type),
            );
        }
    }

    /**
     * Require the public surface to be closed: no promised signature may name an unclassified type.
     *
     * This is the check that stops the contract leaking. An interface an author implements may only
     * mention types the author is allowed to know about — anything else would hand them an internal
     * class and then decompose it underneath them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePublicSurfaceIsClosedOverTheTypesItNames(): void
    {
        $classification = self::classification();
        $classified = [];
        $types = $classification['types'];
        self::assertIsArray($types);
        foreach ($types as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['type'] ?? null);
            $classified[$entry['type']] = true;
        }
        $external = $classification['external_types'];
        self::assertIsArray($external);
        foreach ($external as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['type'] ?? null);
            $classified[$entry['type']] = true;
        }

        $leaked = [];
        foreach ($this->pinnedInterfaces() as $interface) {
            foreach ((new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $interface) {
                    continue;
                }
                foreach ($this->referencedTypes($method) as $referenced) {
                    if (!isset($classified[$referenced])) {
                        $leaked[$referenced] = $interface;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $leaked,
            'These types appear in a promised signature and are not classified public: '
            . implode(', ', array_keys($leaked)),
        );
    }

    /**
     * Require each declared SPI generation to name the version the code actually enforces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachSpiGenerationNamesTheVersionTheCodeEnforces(): void
    {
        $versions = [];
        $spiGenerations = self::generations()['spi_generations'];
        self::assertIsArray($spiGenerations);
        foreach ($spiGenerations as $entry) {
            self::assertIsArray($entry);
            $constant = $entry['constant'];
            self::assertIsString($constant);
            [$class, $name] = explode('::', $constant, 2);
            self::assertTrue(defined($class . '::' . $name), sprintf('%s is not defined.', $constant));
            self::assertSame(constant($class . '::' . $name), $entry['version'] ?? null);
            $versions[] = $entry['version'];
            foreach ($entry['surfaces'] as $surface) {
                self::assertContains($surface, (new ExtensionContributionRegistrySet())->surfaceKeys());
            }
        }

        self::assertSame(
            [
                ManifestContributionSet::SPI_VERSION,
                ManifestContributionSet::CURRENT_SPI_VERSION,
                ManifestContributionSet::COMPOSITION_SPI_VERSION,
            ],
            $versions,
        );
    }

    #[DataProvider('manifestGenerations')]
    /**
     * Require each manifest generation to bind to the SPI version the contract records, and no other.
     *
     * @param   int   $schema  Manifest schema under test.
     * @param   ?int  $spi     Contribution SPI the contract binds that schema to; null for schema 1.
     * @param   bool  $closed  Whether the contract records the generation as closed to unknown keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachManifestGenerationBindsToItsRecordedSpiVersion(int $schema, ?int $spi, bool $closed): void
    {
        self::assertSame($schema >= 2, $closed, 'Typed contributions and the closed manifest arrived together.');
        if ($spi === null) {
            self::assertSame(1, $schema, 'Only manifest generation 1 predates typed contributions.');

            return;
        }
        $manifest = ExtensionManifest::fromJson($this->manifestJson($schema, $spi));
        self::assertSame($spi, $manifest->contributions()->spiVersion());

        $this->expectException(InvalidArgumentException::class);
        ExtensionManifest::fromJson($this->manifestJson($schema, $spi === 1 ? 2 : 1));
    }

    #[DataProvider('manifestGenerations')]
    /**
     * Require the generations recorded as closed to unknown keys to actually refuse one.
     *
     * @param   int   $schema  Manifest schema under test.
     * @param   ?int  $spi     Contribution SPI the contract binds that schema to; null for schema 1.
     * @param   bool  $closed  Whether the contract records the generation as closed to unknown keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownManifestKeysAreRefusedExactlyWhereTheContractSaysTheyAre(
        int $schema,
        ?int $spi,
        bool $closed,
    ): void {
        $json = $this->manifestJson($schema, $spi);
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['unmistakably_not_a_manifest_key'] = true;
        $widened = json_encode($document, JSON_THROW_ON_ERROR);
        self::assertIsString($widened);

        if (!$closed) {
            self::assertSame($schema, ExtensionManifest::fromJson($widened)->schemaVersion());

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        ExtensionManifest::fromJson($widened);
    }

    /**
     * Require every withdrawn type to be gone, and to stay named so an author learns where it went.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWithdrawnTypesAreGoneAndStillRecorded(): void
    {
        $withdrawn = self::generations()['withdrawn'];
        self::assertIsArray($withdrawn);
        self::assertNotSame([], $withdrawn);

        foreach ($withdrawn as $entry) {
            self::assertIsArray($entry);
            $type = $entry['type'];
            self::assertIsString($type);
            self::assertFalse(
                interface_exists($type) || class_exists($type) || enum_exists($type),
                sprintf('Type %s is recorded as withdrawn and still loads.', $type),
            );
            self::assertIsString($entry['withdrawn_in'] ?? null);
            self::assertNotSame('', $entry['reason'] ?? null);
        }
    }

    /**
     * Require every allowlisted host service to be a type a package can actually depend on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryAllowlistedHostServiceResolvesToAPublicType(): void
    {
        $services = self::generations()['host_services']['services'];
        self::assertIsArray($services);
        self::assertNotSame([], $services);

        $classified = [];
        $types = self::classification()['types'];
        self::assertIsArray($types);
        foreach ($types as $entry) {
            self::assertIsArray($entry);
            $classified[$entry['type']] = $entry['role'] ?? null;
        }

        foreach ($services as $service) {
            self::assertIsString($service);
            self::assertTrue(
                interface_exists($service) || class_exists($service),
                sprintf('Host service %s does not exist.', $service),
            );
            self::assertSame('resolve', $classified[$service] ?? null);
        }
    }

    /**
     * Enumerate the manifest generations under test with the bindings the contract records.
     *
     * @return  iterable<string, array{int, ?int, bool}>  Schema, contribution SPI, and closed-key flag.
     *
     * @since   2.0.0
     */
    public static function manifestGenerations(): iterable
    {
        $manifestGenerations = self::generations()['manifest_generations'];
        self::assertIsArray($manifestGenerations);
        foreach ($manifestGenerations as $entry) {
            self::assertIsArray($entry);
            $id = $entry['id'];
            self::assertIsString($id);
            $schema = $entry['schema'];
            self::assertIsInt($schema);
            $spi = $entry['spi'];
            self::assertTrue($spi === null || is_int($spi));
            $closed = $entry['closed_to_unknown_keys'];
            self::assertIsBool($closed);
            yield $id => [$schema, $spi, $closed];
        }
    }

    /**
     * Build the smallest manifest a generation accepts, at a chosen contribution SPI version.
     *
     * @param   int   $schema  Manifest schema to declare.
     * @param   ?int  $spi     Contribution SPI to declare; null omits the contributions object.
     *
     * @return  string  Manifest document.
     *
     * @since   2.0.0
     */
    private function manifestJson(int $schema, ?int $spi): string
    {
        $document = [
            'schema' => $schema,
            'name' => 'kumwe/contract-freeze-probe',
            'type' => 'component',
            'version' => '1.0.0',
            'provider' => 'KumweContract\\Probe\\Provider',
            'autoload' => ['psr-4' => ['KumweContract\\Probe\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
        ];
        if ($spi !== null) {
            $document['contributions'] = ['version' => $spi, 'capabilities' => []];
        }
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        self::assertIsString($json);

        return $json;
    }

    /**
     * Name every interface the compatibility fixtures pin.
     *
     * @return  list<string>  Fully qualified interface names.
     *
     * @since   2.0.0
     */
    private function pinnedInterfaces(): array
    {
        $interfaces = [];
        foreach (glob(dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/*.json') ?: [] as $path) {
            $json = file_get_contents($path);
            self::assertIsString($json);
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            foreach (array_keys($document['interfaces'] ?? []) as $interface) {
                $interfaces[(string) $interface] = true;
            }
            if (is_string($document['interface'] ?? null)) {
                $interfaces[$document['interface']] = true;
            }
        }
        $names = array_keys($interfaces);
        sort($names, SORT_STRING);
        self::assertNotSame([], $names);

        return $names;
    }

    /**
     * Collect the class-like types one promised method signature names.
     *
     * @param   ReflectionMethod  $method  Declared public interface method.
     *
     * @return  list<string>  Fully qualified type names, scalars omitted.
     *
     * @since   2.0.0
     */
    private function referencedTypes(ReflectionMethod $method): array
    {
        $names = [];
        $candidates = array_map(
            static fn (\ReflectionParameter $parameter): ?\ReflectionType => $parameter->getType(),
            $method->getParameters(),
        );
        $candidates[] = $method->getReturnType();
        foreach ($candidates as $type) {
            foreach ($this->flatten($type) as $name) {
                if (interface_exists($name) || class_exists($name) || enum_exists($name)) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Reduce a reflected type to the plain names it is built from.
     *
     * @param   ?\ReflectionType  $type  Reflected type, or null when none is declared.
     *
     * @return  list<string>  Component type names.
     *
     * @since   2.0.0
     */
    private function flatten(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $part) {
                $names = [...$names, ...$this->flatten($part)];
            }

            return $names;
        }

        return [];
    }

    /**
     * Load the frozen generation contract.
     *
     * @return  array<string, mixed>  Decoded contract document.
     *
     * @since   2.0.0
     */
    private static function generations(): array
    {
        return self::document('docs/extension-contract/generations.json', 'kumwe-extension-contract-generations-v1');
    }

    /**
     * Load the machine-readable public and internal classification.
     *
     * @return  array<string, mixed>  Decoded classification document.
     *
     * @since   2.0.0
     */
    private static function classification(): array
    {
        return self::document(
            'docs/extension-contract/classification.json',
            'kumwe-extension-contract-classification-v1',
        );
    }

    /**
     * Read one contract document and prove it declares the format this test understands.
     *
     * @param   string  $path    Repository-relative document path.
     * @param   string  $format  Format identifier the document must declare.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private static function document(string $path, string $format): array
    {
        $json = file_get_contents(dirname(__DIR__, 4) . '/' . $path);
        self::assertIsString($json);
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame($format, $document['format'] ?? null);

        return $document;
    }
}
