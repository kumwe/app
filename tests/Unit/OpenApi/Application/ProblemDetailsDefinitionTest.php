<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Application;

use InvalidArgumentException;
use Kumwe\App\OpenApi\Application\ProblemDetailsDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves one problem definition closes identity, retry and extension response boundaries.
 *
 * @since  2.0.0
 */
#[CoversClass(ProblemDetailsDefinition::class)]
final class ProblemDetailsDefinitionTest extends TestCase
{
    /**
     * Retain the complete machine row and accept the one declared structured extension.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportsAndValidatesTheClosedDefinition(): void
    {
        $extensions = [
            'violations' => [
                'required' => true,
                'schema' => ['type' => 'array'],
            ],
        ];
        $definition = new ProblemDetailsDefinition(
            'urn:kumwe:problem:validation-failed',
            422,
            true,
            30,
            $extensions,
        );

        $definition->validateExtensions(['violations' => [[
            'field' => 'reference',
            'code' => 'required',
            'message' => 'A reference is required.',
        ]]]);

        self::assertSame([
            'type' => 'urn:kumwe:problem:validation-failed',
            'status' => 422,
            'retryable' => true,
            'retry_after_seconds' => 30,
            'extensions' => $extensions,
        ], $definition->toArray());
    }

    /**
     * Refuse malformed public identity, status, retry and extension declarations at construction.
     *
     * @param   list<mixed>  $arguments  Constructor arguments that violate one closed boundary.
     * @param   string       $message    Stable refusal message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidDefinitions')]
    public function testRejectsMalformedDefinitionBoundaries(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ProblemDetailsDefinition(...$arguments);
    }

    /**
     * Supply each malformed definition boundary independently.
     *
     * @return  iterable<string, array{list<mixed>, string}>  Invalid constructor cases.
     *
     * @since   2.0.0
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'unstable problem identifier' => [
            ['https://example.test/problems/failure', 400],
            'requires a stable problem URN',
        ];
        yield 'successful HTTP status' => [
            ['urn:kumwe:problem:failure', 200],
            'requires a failure status',
        ];
        yield 'delay on a non-retryable problem' => [
            ['urn:kumwe:problem:failure', 503, false, 30],
            'retry delay is invalid',
        ];
        yield 'malformed extension schema' => [
            ['urn:kumwe:problem:failure', 400, false, null, [
                'bad-name' => ['required' => true, 'schema' => []],
            ]],
            'extension declaration is invalid',
        ];
    }

    /**
     * Refuse response members outside the declared extension vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnUndeclaredResponseExtension(): void
    {
        $definition = new ProblemDetailsDefinition('urn:kumwe:problem:failure', 400);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not declare this extension member');

        $definition->validateExtensions(['debug' => true]);
    }

    /**
     * Refuse absent, empty and malformed structured validation members.
     *
     * @param   array<string, mixed>  $extensions  Candidate response extension members.
     * @param   string                $message     Stable refusal message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidViolationMembers')]
    public function testRejectsInvalidViolationMembers(array $extensions, string $message): void
    {
        $definition = new ProblemDetailsDefinition(
            'urn:kumwe:problem:validation-failed',
            422,
            extensions: [
                'violations' => [
                    'required' => true,
                    'schema' => ['type' => 'array'],
                ],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $definition->validateExtensions($extensions);
    }

    /**
     * Supply malformed required violation members independently.
     *
     * @return  iterable<string, array{array<string, mixed>, string}>  Invalid response members.
     *
     * @since   2.0.0
     */
    public static function invalidViolationMembers(): iterable
    {
        yield 'required member absent' => [
            [],
            'missing a required extension member',
        ];
        yield 'violations list empty' => [
            ['violations' => []],
            'must be a non-empty bounded list',
        ];
        yield 'violation row malformed' => [
            ['violations' => [[
                'field' => 'Reference',
                'code' => 'required',
                'message' => 'A reference is required.',
            ]]],
            'validation violation is malformed',
        ];
    }
}
