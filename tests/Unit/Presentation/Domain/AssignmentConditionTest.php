<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use Kumwe\CMS\Presentation\Domain\AssignmentCondition;
use Kumwe\CMS\Presentation\Domain\ConditionType;
use Kumwe\CMS\Presentation\Domain\PresentationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssignmentCondition::class)]
#[CoversClass(PresentationContext::class)]
#[UsesClass(ConditionType::class)]
final class AssignmentConditionTest extends TestCase
{
    public function testMatchesClosedTypedContextDimensions(): void
    {
        $context = new PresentationContext('content.view', 'menu-1', 'en-NA', ['editor']);

        self::assertTrue((new AssignmentCondition(ConditionType::Route, 'content.view'))->matches($context));
        self::assertTrue((new AssignmentCondition(ConditionType::Menu, 'menu-1'))->matches($context));
        self::assertTrue((new AssignmentCondition(ConditionType::Locale, 'en-NA'))->matches($context));
        self::assertTrue((new AssignmentCondition(ConditionType::Role, 'editor'))->matches($context));
        self::assertFalse((new AssignmentCondition(ConditionType::Role, 'owner'))->matches($context));
    }

    public function testSerializesAsDataWithoutExecutableExpression(): void
    {
        self::assertSame(
            ['type' => 'route', 'value' => 'content.view'],
            (new AssignmentCondition(ConditionType::Route, 'content.view'))->toArray(),
        );
    }
}
