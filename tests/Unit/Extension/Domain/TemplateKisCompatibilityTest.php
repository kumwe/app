<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\SemanticVersion;
use Kumwe\App\Extension\Domain\TemplateKisCompatibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies template KIS declarations are closed, ordered, and independently bounded.
 *
 * @since  2.0.0
 */
#[CoversClass(TemplateKisCompatibility::class)]
final class TemplateKisCompatibilityTest extends TestCase
{
    /**
     * Proves the legacy constructor is an exact compatibility point rather than an unbounded range.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacyKisOneCompatibilityIsExact(): void
    {
        $compatibility = TemplateKisCompatibility::legacyKisOne();

        self::assertSame(1, $compatibility->contract());
        self::assertSame('kis-1.0', $compatibility->standard());
        self::assertTrue($compatibility->supportsComponents(SemanticVersion::fromString('1.0.0')));
        self::assertFalse($compatibility->supportsComponents(SemanticVersion::fromString('1.0.1')));
        self::assertTrue($compatibility->supportsTokens(SemanticVersion::fromString('1.0.0')));
        self::assertFalse($compatibility->supportsTokens(SemanticVersion::fromString('0.9.9')));
    }

    /**
     * Proves a well-formed declaration preserves independent component and token ranges.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testParsesVersionedBoundedCompatibility(): void
    {
        $compatibility = TemplateKisCompatibility::fromArray(self::validDeclaration());

        self::assertSame(1, $compatibility->contract());
        self::assertSame('kis-1.0', $compatibility->standard());
        self::assertTrue($compatibility->supportsComponents(SemanticVersion::fromString('1.1.0')));
        self::assertFalse($compatibility->supportsComponents(SemanticVersion::fromString('2.0.0')));
        self::assertTrue($compatibility->supportsTokens(SemanticVersion::fromString('1.0.0')));
        self::assertFalse($compatibility->supportsTokens(SemanticVersion::fromString('1.0.1')));
    }

    /**
     * Proves malformed or open-ended compatibility declarations fail closed.
     *
     * @param   array<string, mixed>  $declaration  Adversarial template compatibility declaration.
     * @param   string                $message      Failure fragment identifying the rejected boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedDeclarations')]
    public function testRejectsMalformedDeclarations(array $declaration, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        TemplateKisCompatibility::fromArray($declaration);
    }

    /**
     * Supply malformed, incomplete, and unordered contract examples.
     *
     * @return  array<string, array{array<string, mixed>, string}>  Cases keyed by rejected invariant.
     *
     * @since   2.0.0
     */
    public static function malformedDeclarations(): array
    {
        $unknown = self::validDeclaration();
        $unknown['future'] = true;
        $unsupportedContract = self::validDeclaration();
        $unsupportedContract['contract'] = 2;
        $invalidStandard = self::validDeclaration();
        $invalidStandard['standard'] = '^1.0';
        $missingRange = self::validDeclaration();
        unset($missingRange['components']);
        $openRange = self::validDeclaration();
        $openRange['tokens'] = ['minimum' => '1.0.0'];
        $reversedRange = self::validDeclaration();
        $reversedRange['components'] = ['minimum' => '2.0.0', 'maximum' => '1.0.0'];

        return [
            'unknown key' => [$unknown, 'unknown key future'],
            'unsupported contract' => [$unsupportedContract, 'contract must be version 1'],
            'invalid standard' => [$invalidStandard, 'kis-major.minor'],
            'missing range' => [$missingRange, 'components range must be a JSON object'],
            'open range' => [$openRange, 'requires string minimum and maximum'],
            'reversed range' => [$reversedRange, 'maximum cannot precede its minimum'],
        ];
    }

    /**
     * Build the canonical version-one compatibility declaration.
     *
     * @return  array<string, mixed>  Valid closed declaration ready for mutation by a test case.
     *
     * @since   2.0.0
     */
    private static function validDeclaration(): array
    {
        return [
            'contract' => 1,
            'standard' => 'kis-1.0',
            'components' => ['minimum' => '1.0.0', 'maximum' => '1.2.0'],
            'tokens' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
        ];
    }
}
