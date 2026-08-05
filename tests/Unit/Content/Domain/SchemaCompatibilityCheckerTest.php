<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Domain;

use Kumwe\CMS\Content\Domain\SchemaCompatibilityChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaCompatibilityChecker::class)]
final class SchemaCompatibilityCheckerTest extends TestCase
{
    public function testReportsConstraintsThatRejectPreviouslyValidContent(): void
    {
        $before = [
            'type' => 'object',
            'properties' => [
                'body' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => ['news', 'events']],
            ],
        ];
        $after = [
            'type' => 'object',
            'properties' => [
                'body' => ['type' => 'string', 'minLength' => 10],
                'category' => ['type' => 'string', 'enum' => ['news']],
            ],
            'required' => ['body'],
            'additionalProperties' => false,
        ];

        self::assertSame([
            'disallowed additional fields',
            'made field required body',
            'narrowed enum of category',
            'raised minLength of body',
        ], (new SchemaCompatibilityChecker())->breakingChanges($before, $after));
    }

    public function testAllowsAdditiveOptionalFieldsAndWiderBounds(): void
    {
        $before = [
            'type' => 'object',
            'properties' => ['score' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 10]],
        ];
        $after = [
            'type' => 'object',
            'properties' => [
                'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'summary' => ['type' => 'string'],
            ],
        ];

        self::assertSame([], (new SchemaCompatibilityChecker())->breakingChanges($before, $after));
    }
}
