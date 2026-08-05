<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Content\Domain\InvalidContentData;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonSchemaValidator::class)]
#[UsesClass(InvalidContentData::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ARTICLE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'body' => ['type' => 'string', 'minLength' => 1],
            'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
        ],
        'required' => ['body'],
        'additionalProperties' => false,
    ];

    public function testAcceptsDataThatSatisfiesPersistedSchema(): void
    {
        (new JsonSchemaValidator())->assertValid(self::ARTICLE_SCHEMA, [
            'body' => 'Published from a versioned content type.',
            'priority' => 3,
        ]);

        self::addToAssertionCount(1);
    }

    public function testReportsEveryInvalidContentField(): void
    {
        try {
            (new JsonSchemaValidator())->assertValid(self::ARTICLE_SCHEMA, [
                'priority' => 8,
                'unknown' => true,
            ]);
            self::fail('Invalid content data was accepted.');
        } catch (InvalidContentData $exception) {
            self::assertStringContainsString('$.body is required', $exception->getMessage());
            self::assertStringContainsString('$.priority is above maximum', $exception->getMessage());
            self::assertStringContainsString('$.unknown is not allowed', $exception->getMessage());
        }
    }

    public function testRejectsUnsupportedSchemaKeywordsBeforePublication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported keyword');

        (new JsonSchemaValidator())->assertSupported([
            'type' => 'object',
            'unevaluatedProperties' => false,
        ]);
    }
}
