<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Development;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

#[CoversNothing]
/**
 * Locks the interface and enum surface extension packages compile against for contribution SPI 2.
 *
 * @since  2.0.0
 */
final class ExtensionPublicApiCompatibilityFixtureTest extends TestCase
{
    /**
     * Require every declared extension-facing interface to retain its exact method signatures.
     *
     * Adding an abstract interface method is intentionally treated as breaking because existing providers would
     * otherwise fail to load. A future incompatible API must publish a new SPI fixture instead of rewriting this one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributionSpiTwoInterfacesRemainSourceCompatible(): void
    {
        $fixture = $this->fixture();
        $interfaces = $fixture['interfaces'] ?? null;
        self::assertIsArray($interfaces);

        foreach ($interfaces as $interface => $expected) {
            self::assertIsString($interface);
            self::assertIsArray($expected);
            self::assertTrue(interface_exists($interface), sprintf('Missing public interface %s.', $interface));
            $reflection = new ReflectionClass($interface);
            $actual = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $interface) {
                    $actual[] = $this->signature($method);
                }
            }
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public interface %s changed.', $interface));
        }
    }

    /**
     * Pin the additive KIS registrar independently from the frozen contribution SPI-two interface.
     *
     * Existing providers continue accepting `ExtensionContributionRegistrar`; providers that publish
     * semantic surfaces explicitly feature-detect this one-method additive contract. Keeping its bytes
     * separate prevents a KIS addition from rewriting the established SPI-two compatibility baseline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdditiveInterfaceSurfaceRegistrarRemainsSourceCompatible(): void
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/interface-surface-registrar-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            '4463c89cc8fcb877dc624611ca0c9b217246a2a7b9cd18aec62018b016959d54',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-interface-surface-registrar-v1', $fixture['format'] ?? null);
        $interface = $fixture['interface'] ?? null;
        self::assertIsString($interface);
        self::assertTrue(interface_exists($interface));
        $reflection = new ReflectionClass($interface);
        $actual = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $interface) {
                $actual[] = $this->signature($method);
            }
        }
        sort($actual, SORT_STRING);
        $expected = $fixture['methods'] ?? null;
        self::assertIsArray($expected);
        sort($expected, SORT_STRING);

        self::assertSame($expected, $actual);
    }

    /**
     * Pin the additive money rate-provider contracts a rate package compiles against.
     *
     * Core owns the money conversion contract and ships no rate, so these three declarations are the
     * whole of the surface a rate package touches: the registrar it contributes through, the port it
     * implements, and the rounding vocabulary a conversion is declared in. They are pinned separately
     * from the frozen SPI-two baseline for the same reason the KIS registrar is — an addition here must
     * not rewrite bytes existing providers were admitted against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdditiveMoneyRateProviderContractsRemainSourceCompatible(): void
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/money-rate-provider-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            '301b9778de8a782cc387925729e3a88d7e67d80198b4da1364e104e5509a8b65',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-money-rate-provider-v1', $fixture['format'] ?? null);
        $interfaces = $fixture['interfaces'] ?? null;
        self::assertIsArray($interfaces);
        foreach ($interfaces as $interface => $expected) {
            self::assertIsString($interface);
            self::assertIsArray($expected);
            self::assertTrue(interface_exists($interface), sprintf('Missing public interface %s.', $interface));
            $actual = [];
            foreach ((new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $interface) {
                    $actual[] = $this->signature($method);
                }
            }
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public interface %s changed.', $interface));
        }
        $enums = $fixture['enums'] ?? null;
        self::assertIsArray($enums);
        foreach ($enums as $enum => $expected) {
            self::assertIsString($enum);
            self::assertIsArray($expected);
            self::assertTrue(enum_exists($enum), sprintf('Missing public enum %s.', $enum));
            $actual = [];
            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                self::assertInstanceOf(ReflectionEnumBackedCase::class, $case);
                $actual[$case->getName()] = $case->getBackingValue();
            }
            ksort($actual, SORT_STRING);
            ksort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public enum %s changed.', $enum));
        }
    }

    /**
     * Pin the additive unit conversion contracts a conversion-table package compiles against.
     *
     * Core owns the unit conversion contract and ships no table, so these three declarations are the
     * whole of the surface such a package touches: the registrar it contributes through, the port it
     * implements, and the rounding vocabulary a conversion is declared in. They are pinned separately
     * from the frozen SPI-two baseline, and separately from the money fixture, for the same reason the
     * KIS registrar is — an addition here must not rewrite bytes existing providers were admitted
     * against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdditiveUnitConversionContractsRemainSourceCompatible(): void
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/unit-conversion-provider-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            '1b8b5d2c0227138ca9e1e0709b58365f6c332c7ba6aa0a628f47dce83563cb42',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-unit-conversion-provider-v1', $fixture['format'] ?? null);
        $interfaces = $fixture['interfaces'] ?? null;
        self::assertIsArray($interfaces);
        foreach ($interfaces as $interface => $expected) {
            self::assertIsString($interface);
            self::assertIsArray($expected);
            self::assertTrue(interface_exists($interface), sprintf('Missing public interface %s.', $interface));
            $actual = [];
            foreach ((new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $interface) {
                    $actual[] = $this->signature($method);
                }
            }
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public interface %s changed.', $interface));
        }
        $enums = $fixture['enums'] ?? null;
        self::assertIsArray($enums);
        foreach ($enums as $enum => $expected) {
            self::assertIsString($enum);
            self::assertIsArray($expected);
            self::assertTrue(enum_exists($enum), sprintf('Missing public enum %s.', $enum));
            $actual = [];
            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                self::assertInstanceOf(ReflectionEnumBackedCase::class, $case);
                $actual[$case->getName()] = $case->getBackingValue();
            }
            ksort($actual, SORT_STRING);
            ksort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public enum %s changed.', $enum));
        }
    }

    /**
     * Require stable enum names and backed values used in signed manifests and durable rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributionSpiTwoEnumsRemainWireCompatible(): void
    {
        $fixture = $this->fixture();
        $enums = $fixture['enums'] ?? null;
        self::assertIsArray($enums);

        foreach ($enums as $enum => $expected) {
            self::assertIsString($enum);
            self::assertIsArray($expected);
            self::assertTrue(enum_exists($enum), sprintf('Missing public enum %s.', $enum));
            $actual = [];
            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                self::assertInstanceOf(ReflectionEnumBackedCase::class, $case);
                $actual[$case->getName()] = $case->getBackingValue();
            }
            ksort($actual, SORT_STRING);
            ksort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public enum %s changed.', $enum));
        }
    }

    /**
     * Load the immutable public API fixture as a keyed document.
     *
     * @return  array<string, mixed>  Compatibility fixture document.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/public-interfaces-v2.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            'a155c13a5a8271c8ff92288626cce70476a751881c9b4d8ebb670deda2cf1bfb',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-extension-public-interfaces-v2', $fixture['format'] ?? null);

        return $fixture;
    }

    /**
     * Render one declared interface method into the fixture's canonical signature grammar.
     *
     * @param   ReflectionMethod  $method  Declared public interface method.
     *
     * @return  string  Fully-qualified parameter and return signature.
     *
     * @since   2.0.0
     */
    private function signature(ReflectionMethod $method): string
    {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $this->parameter($parameter),
            $method->getParameters(),
        );
        $returnType = $method->getReturnType();
        self::assertNotNull($returnType, sprintf('Public method %s has no return type.', $method->getName()));

        return sprintf(
            '%s(%s): %s',
            $method->getName(),
            implode(', ', $parameters),
            $this->type($returnType),
        );
    }

    /**
     * Render one method parameter, including reference, variadic, and optional-value compatibility.
     *
     * @param   ReflectionParameter  $parameter  Parameter to encode.
     *
     * @return  string  Canonical parameter fragment.
     *
     * @since   2.0.0
     */
    private function parameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        self::assertNotNull($type, sprintf('Public parameter $%s has no type.', $parameter->getName()));
        $rendered = $this->type($type) . ' ';
        if ($parameter->isPassedByReference()) {
            $rendered .= '&';
        }
        if ($parameter->isVariadic()) {
            $rendered .= '...';
        }
        $rendered .= '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $rendered .= ' = ' . ($parameter->isDefaultValueConstant()
                ? $parameter->getDefaultValueConstantName()
                : var_export($parameter->getDefaultValue(), true));
        }

        return $rendered;
    }

    /**
     * Render named, union, and intersection reflection types without source-context aliases.
     *
     * @param   ReflectionType  $type  Reflected type declaration.
     *
     * @return  string  Canonical fully-qualified type expression.
     *
     * @since   2.0.0
     */
    private function type(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' . $name : $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $part): string => $this->type($part), $type->getTypes()));
        }
        self::assertInstanceOf(ReflectionIntersectionType::class, $type);

        return implode('&', array_map(fn (ReflectionType $part): string => $this->type($part), $type->getTypes()));
    }
}
