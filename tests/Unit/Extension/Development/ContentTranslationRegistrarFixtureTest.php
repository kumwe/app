<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Kumwe\CMS\Content\Domain\TranslationGroupDeclaration;
use Kumwe\CMS\Extension\Contribution\ContentTranslationRegistrar;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

#[CoversNothing]
/**
 * Pins the additive contracts a package publishing multilingual content compiles against.
 *
 * They are pinned in a fixture of their own rather than inside the frozen SPI-two baseline, for the same
 * reason the KIS registrar and the rate-provider contracts are: an addition must not rewrite the bytes
 * existing providers were admitted against. What is locked here is the whole of the surface a
 * multilingual content package touches — the one-method registrar it contributes through, the manifest
 * section its declarations are read from, and the members those declarations carry.
 *
 * @since  2.0.0
 */
final class ContentTranslationRegistrarFixtureTest extends TestCase
{
    /**
     * Require the additive registrar to keep the exact signature a published package compiles against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAdditiveContentTranslationRegistrarRemainsSourceCompatible(): void
    {
        $fixture = $this->fixture();
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
        self::assertTrue(is_a(
            \Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar::class,
            ContentTranslationRegistrar::class,
            true,
        ));
    }

    /**
     * Require the declaration a manifest carries to keep exactly the members it was admitted with.
     *
     * The manifest section and the member names are part of the contract because a signed manifest is
     * read before any of a package's code runs; renaming either would silently stop reconciling a
     * published package's content sets.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredManifestSectionAndMembersRemainWireCompatible(): void
    {
        $fixture = $this->fixture();

        self::assertSame('contributions.content.translation_groups', $fixture['manifest_section'] ?? null);
        self::assertSame(
            $fixture['declaration_members'] ?? null,
            array_keys((new TranslationGroupDeclaration('acme.blog.articles', ['en-GB'], 'en-GB'))->toArray()),
        );
    }

    /**
     * Load the immutable compatibility fixture, proving its bytes are the released ones.
     *
     * @return  array<string, mixed>  Compatibility fixture document.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/content-translation-registrar-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            '3a8ee7590524b7cab964078a23bf860c2b47a3c3493d5a8ea50b8af78cf806f8',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-content-translation-registrar-v1', $fixture['format'] ?? null);

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
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);

        return sprintf('%s(%s): %s', $method->getName(), implode(', ', $parameters), $returnType->getName());
    }

    /**
     * Render one method parameter as the fixture spells it.
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
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName() . ' $' . $parameter->getName();
    }
}
