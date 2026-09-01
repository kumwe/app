<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(CanonicalManifestInterpreter::class)]
/**
 * Proves the interpreter refuses a validated SDK graph whose shape or member types drifted.
 *
 * Each case materializes a corrupted `ManifestContributions` value the SDK parser can never emit, so
 * the interpreter's own corruption fences — not the parser — are what the assertions exercise.
 *
 * @since  2.0.0
 */
final class CanonicalManifestInterpreterDriftTest extends TestCase
{
    /**
     * Prove the interpreter exposes the exact canonical declaration graph unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCanonicalDeclarationGraphPassesThroughUnchanged(): void
    {
        $declarations = ['version' => 2, 'integration' => ['reports' => []]];

        self::assertSame(
            $declarations,
            self::interpreter($declarations)->declarations(),
        );
    }

    /**
     * Prove a canonical business definition cannot change package ownership.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABusinessDefinitionOwnedByAnotherPackageIsRefused(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4)
                . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-4/kumwe.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($fixture);
        $foreignDefinition = $fixture['contributions']['business']['definitions'][0];
        self::assertIsArray($foreignDefinition);
        $interpreter = self::interpreter(['business' => ['definitions' => [$foreignDefinition]]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('business definition changed package ownership');

        $interpreter->businessDefinitions();
    }

    /**
     * Prove a graph member that stopped being a list is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGraphMemberThatStoppedBeingAListIsRefused(): void
    {
        $interpreter = self::interpreter(['integration' => ['reports' => 'broken']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('changed shape at integration.reports');

        $interpreter->reports();
    }

    /**
     * Prove a graph list that gained a non-object entry is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGraphListWithANonObjectEntryIsRefused(): void
    {
        $interpreter = self::interpreter(['integration' => ['reports' => ['broken']]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('changed shape at integration.reports');

        $interpreter->reports();
    }

    /**
     * Prove a canonical string member that changed type is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStringMemberThatChangedTypeIsRefused(): void
    {
        $interpreter = self::interpreter(['capabilities' => [['id' => 42]]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('member id changed type');

        $interpreter->capabilities();
    }

    /**
     * Prove a canonical boolean member that changed type is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABooleanMemberThatChangedTypeIsRefused(): void
    {
        $interpreter = self::interpreter(['capabilities' => [
            self::capability(['delegatable' => 'yes']),
        ]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('member delegatable changed type');

        $interpreter->capabilities();
    }

    /**
     * Prove a canonical integer member that changed type is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIntegerMemberThatChangedTypeIsRefused(): void
    {
        $interpreter = self::interpreter(['capabilities' => [
            self::capability(['version' => 'one']),
        ]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('member version changed type');

        $interpreter->capabilities();
    }

    /**
     * Prove a canonical string list that stopped being a list is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStringListMemberThatChangedTypeIsRefused(): void
    {
        $interpreter = self::interpreter(['capabilities' => [
            self::capability(['allowed_scopes' => 'global']),
        ]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('member allowed_scopes changed type');

        $interpreter->capabilities();
    }

    /**
     * Prove a canonical string list holding a non-string entry is refused as corruption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStringListEntryThatChangedTypeIsRefused(): void
    {
        $interpreter = self::interpreter(['capabilities' => [
            self::capability(['allowed_scopes' => ['global', 5]]),
        ]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('member allowed_scopes changed type');

        $interpreter->capabilities();
    }

    /**
     * Build one otherwise-valid capability declaration with selected members corrupted.
     *
     * @param   array<string, mixed>  $corruption  Members replacing the valid defaults.
     *
     * @return  array<string, mixed>  Capability declaration carrying the corruption.
     *
     * @since   2.0.0
     */
    private static function capability(array $corruption): array
    {
        return $corruption + [
            'id' => 'acme.probe.use',
            'label' => 'Use probe',
            'description' => 'Use the probe surfaces.',
            'allowed_scopes' => ['global', 'site'],
            'delegatable' => true,
            'high_impact' => false,
            'lifecycle' => 'active',
            'version' => 1,
        ];
    }

    /**
     * Build an interpreter over a corrupted canonical contribution value.
     *
     * @param   array<string, mixed>  $declarations  Raw canonical declaration graph.
     *
     * @return  CanonicalManifestInterpreter  Host interpreter under test.
     *
     * @since   2.0.0
     */
    private static function interpreter(array $declarations): CanonicalManifestInterpreter
    {
        $reflection = new ReflectionClass(ManifestContributions::class);
        $manifest = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $value = match (true) {
                $property->getName() === 'owner' => ContributionOwner::extension('acme/probe'),
                $property->getName() === 'spiVersion' => 2,
                $property->getName() === 'declarations' => $declarations,
                default => [],
            };
            $property->setValue($manifest, $value);
        }

        return new CanonicalManifestInterpreter($manifest);
    }
}
