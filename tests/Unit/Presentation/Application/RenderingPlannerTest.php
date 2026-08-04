<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use Kumwe\CMS\Presentation\Application\RenderPlan;
use Kumwe\CMS\Presentation\Application\RenderingPlanner;
use Kumwe\CMS\Presentation\Domain\ModuleAssignment;
use Kumwe\CMS\Presentation\Domain\PresentationContext;
use Kumwe\CMS\Presentation\Domain\TemplateAssignment;
use Kumwe\CMS\Presentation\Domain\TemplateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RenderingPlanner::class)]
#[CoversClass(RenderPlan::class)]
#[UsesClass(ModuleAssignment::class)]
#[UsesClass(PresentationContext::class)]
#[UsesClass(TemplateAssignment::class)]
#[UsesClass(TemplateDefinition::class)]
final class RenderingPlannerTest extends TestCase
{
    public function testSelectsHighestPriorityTemplateAndSortsModulesDeterministically(): void
    {
        $context = new PresentationContext('content.view', null, 'en-NA');
        $low = $this->template('018f22e2-7c8b-7ab0-8f3a-88e8026bb210', 'low');
        $high = $this->template('018f22e2-7c8b-7ab0-8f3a-88e8026bb211', 'high');
        $plan = (new RenderingPlanner())->plan(
            $context,
            [
                new TemplateAssignment('018f22e2-7c8b-7ab0-8f3a-88e8026bb212', $low, 10),
                new TemplateAssignment('018f22e2-7c8b-7ab0-8f3a-88e8026bb213', $high, 20),
            ],
            [
                $this->module('018f22e2-7c8b-7ab0-8f3a-88e8026bb214', 20),
                $this->module('018f22e2-7c8b-7ab0-8f3a-88e8026bb215', 10),
            ],
        );

        self::assertSame($high, $plan->template());
        self::assertSame(
            [10, 20],
            array_map(
                static fn (ModuleAssignment $assignment): int => $assignment->position(),
                $plan->modulesBySlot()['sidebar'],
            ),
        );
    }

    public function testRejectsAssignmentToUndeclaredSlot(): void
    {
        $context = new PresentationContext('content.view', null, 'en-NA');
        $template = $this->template('018f22e2-7c8b-7ab0-8f3a-88e8026bb210', 'site');

        $this->expectException(RuntimeException::class);

        (new RenderingPlanner())->plan(
            $context,
            [new TemplateAssignment('018f22e2-7c8b-7ab0-8f3a-88e8026bb212', $template, 10)],
            [new ModuleAssignment(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb214',
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb216',
                'unknown',
                10,
            )],
        );
    }

    private function template(string $id, string $handle): TemplateDefinition
    {
        return new TemplateDefinition($id, $handle, ['main', 'sidebar']);
    }

    private function module(string $id, int $position): ModuleAssignment
    {
        return new ModuleAssignment(
            $id,
            str_replace('bb21', 'bb22', $id),
            'sidebar',
            $position,
        );
    }
}
