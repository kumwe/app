<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Workflow\Domain;

use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Workflow\Domain\InvalidWorkflowTransition;
use Kumwe\CMS\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Workflow::class)]
#[UsesClass(ContentStatus::class)]
#[UsesClass(InvalidWorkflowTransition::class)]
final class WorkflowTest extends TestCase
{
    public function testExposesClosedAllowedTargets(): void
    {
        $workflow = new Workflow();

        self::assertSame(
            [ContentStatus::Review, ContentStatus::Archived],
            $workflow->allowedTargets(ContentStatus::Draft),
        );
        self::assertSame(
            [ContentStatus::Draft, ContentStatus::Published, ContentStatus::Archived],
            $workflow->allowedTargets(ContentStatus::Review),
        );
    }

    public function testAllowsOnlyDeclaredTransition(): void
    {
        $workflow = new Workflow();

        self::assertTrue($workflow->allows(ContentStatus::Draft, ContentStatus::Review));
        self::assertFalse($workflow->allows(ContentStatus::Draft, ContentStatus::Published));
        self::assertFalse($workflow->allows(ContentStatus::Published, ContentStatus::Published));
    }

    public function testRejectsUndeclaredTransition(): void
    {
        $this->expectException(InvalidWorkflowTransition::class);

        (new Workflow())->assertCanTransition(ContentStatus::Archived, ContentStatus::Published);
    }
}
