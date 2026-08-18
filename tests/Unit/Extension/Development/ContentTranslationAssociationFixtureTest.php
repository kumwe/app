<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Extension\Contribution\TranslationSetItemAssociation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

#[CoversClass(TranslationSetItemAssociation::class)]
/**
 * Pins the generation-one item-association contract a multilingual content package compiles against.
 *
 * The declaration half of the contract is pinned by `ContentTranslationRegistrarFixtureTest`; this is
 * the runtime half — the association type a package constructs, the members it exports, the group
 * derivation core promises to resolve it through, and the one `ContentService` method it is handed to.
 * The derivation is pinned to a concrete recorded value rather than only to its recipe, because the
 * derivation is what makes every stored `translation_group_id` a durable link back to a declared set:
 * a change that still satisfied the recipe's wording but hashed differently would detach every already
 * stored item, and the byte-for-byte example is what makes that impossible to do quietly.
 *
 * It attributes to `TranslationSetItemAssociation` rather than declaring that it covers nothing,
 * because it does not only read a fixture: it builds a real association and holds its exported members
 * and its derived group to the recorded ones. The `ContentService` method is asserted structurally,
 * which is a shape assertion rather than a covered execution path.
 *
 * @since  2.0.0
 */
final class ContentTranslationAssociationFixtureTest extends TestCase
{
    /**
     * Require the association type to keep the exact export and generation a package compiles against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAssociationKeepsItsRecordedMembersAndGeneration(): void
    {
        $fixture = $this->fixture();

        self::assertSame(TranslationSetItemAssociation::class, $fixture['association_class'] ?? null);
        self::assertSame($fixture['generation'] ?? null, TranslationSetItemAssociation::GENERATION);

        $association = new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles');
        self::assertSame($fixture['association_members'] ?? null, array_keys($association->toArray()));
        self::assertSame(
            [
                'generation' => 1,
                'owner' => 'acme/blog',
                'translation_set' => 'acme.blog.articles',
            ],
            $association->toArray(),
        );
    }

    /**
     * Require the group derivation to reproduce the recorded example byte for byte.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGroupDerivationReproducesTheRecordedExample(): void
    {
        $fixture = $this->fixture();
        $derivation = $fixture['group_derivation'] ?? null;
        self::assertIsArray($derivation);
        self::assertSame('uuid5', $derivation['algorithm'] ?? null);
        self::assertSame(TranslationSetItemAssociation::GROUP_NAMESPACE, $derivation['namespace'] ?? null);
        self::assertSame('{generation}|{site}|{owner}|{translation_set}', $derivation['name'] ?? null);

        $example = $derivation['example'] ?? null;
        self::assertIsArray($example);
        self::assertIsString($example['owner'] ?? null);
        self::assertIsString($example['translation_set'] ?? null);
        self::assertIsString($example['site'] ?? null);
        self::assertSame(TranslationSetItemAssociation::GENERATION, $example['generation'] ?? null);
        $association = new TranslationSetItemAssociation($example['owner'], $example['translation_set']);

        self::assertSame($example['group_id'] ?? null, $association->groupIdForSite($example['site']));
    }

    /**
     * Require the one application path the association travels to keep its recorded signature.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheContentServiceMethodRemainsSourceCompatible(): void
    {
        $fixture = $this->fixture();
        $path = $fixture['application_path'] ?? null;
        self::assertIsArray($path);
        self::assertSame(ContentService::class, $path['service'] ?? null);

        $recorded = $path['method'] ?? null;
        self::assertIsString($recorded);
        [$name] = explode('(', $recorded, 2);
        self::assertTrue(method_exists(ContentService::class, $name));

        self::assertSame($recorded, $this->signature(new ReflectionMethod(ContentService::class, $name)));
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
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/content-translation-association-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            'a7650ea384986e2022e9a3eb9f5c84768aff421229741b5b9cec68cc8b0997cd',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-content-translation-association-v1', $fixture['format'] ?? null);

        return $fixture;
    }

    /**
     * Render one promised method into the fixture's canonical signature grammar.
     *
     * @param   ReflectionMethod  $method  Declared public method.
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
