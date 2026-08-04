<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use Kumwe\CMS\Presentation\Domain\AssignmentCondition;
use Kumwe\CMS\Presentation\Domain\ConditionType;
use Kumwe\CMS\Presentation\Domain\ModuleAssignment;
use Kumwe\CMS\Presentation\Domain\PresentationContext;
use Kumwe\CMS\Presentation\Domain\TemplateAssignment;
use Kumwe\CMS\Presentation\Domain\TemplateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateAssignment::class)]
#[CoversClass(ModuleAssignment::class)]
#[UsesClass(AssignmentCondition::class)]
#[UsesClass(ConditionType::class)]
#[UsesClass(PresentationContext::class)]
#[UsesClass(TemplateDefinition::class)]
final class AssignmentTest extends TestCase
{
    public function testAssignmentsRequireEveryTypedCondition(): void
    {
        $context = new PresentationContext('content.view', null, 'en-NA');
        $condition = new AssignmentCondition(ConditionType::Route, 'content.view');
        $template = new TemplateDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb210',
            'core.site',
            ['sidebar'],
        );
        $templateAssignment = new TemplateAssignment(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb212',
            $template,
            100,
            [$condition],
        );
        $moduleAssignment = new ModuleAssignment(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb213',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb214',
            'sidebar',
            10,
            [$condition],
        );

        self::assertTrue($templateAssignment->matches($context));
        self::assertTrue($moduleAssignment->matches($context));
        self::assertSame(100, $templateAssignment->priority());
        self::assertSame(10, $moduleAssignment->position());
    }

    public function testDisabledAssignmentsNeverMatch(): void
    {
        $context = new PresentationContext('content.view', null, 'en-NA');
        $template = new TemplateDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb210',
            'core.site',
            ['main'],
        );

        self::assertFalse((new TemplateAssignment(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb212',
            $template,
            0,
            enabled: false,
        ))->matches($context));
    }
}
