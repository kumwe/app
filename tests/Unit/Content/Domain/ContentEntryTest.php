<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\ExpectedVersion;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Content\Domain\VersionConflict;
use Kumwe\CMS\Workflow\Domain\InvalidWorkflowTransition;
use Kumwe\CMS\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentEntry::class)]
#[UsesClass(ContentStatus::class)]
#[UsesClass(ExpectedVersion::class)]
#[UsesClass(PublicationWindow::class)]
#[UsesClass(VersionConflict::class)]
#[UsesClass(Workflow::class)]
#[UsesClass(InvalidWorkflowTransition::class)]
final class ContentEntryTest extends TestCase
{
    private const ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb151';

    public function testCreatesValidatedVersionOneAggregate(): void
    {
        $entry = ContentEntry::create(self::ID, '  Welcome  ', 'welcome', ['body' => 'Hello']);

        self::assertSame(self::ID, $entry->id());
        self::assertSame('Welcome', $entry->title());
        self::assertSame('welcome', $entry->slug());
        self::assertSame(['body' => 'Hello'], $entry->data());
        self::assertSame(ContentStatus::Draft, $entry->status());
        self::assertSame(1, $entry->version());
    }

    public function testRevisionReturnsNewAggregateAndIncrementsVersion(): void
    {
        $original = ContentEntry::create(self::ID, 'Welcome', 'welcome');
        $revised = $original->revise(new ExpectedVersion(1), 'About', 'about', ['body' => 'About us']);

        self::assertSame('Welcome', $original->title());
        self::assertSame(1, $original->version());
        self::assertSame('About', $revised->title());
        self::assertSame(2, $revised->version());
    }

    public function testEveryMutationEnforcesExpectedVersion(): void
    {
        $entry = ContentEntry::create(self::ID, 'Welcome', 'welcome');

        $this->expectException(VersionConflict::class);

        $entry->reschedule(new ExpectedVersion(2), PublicationWindow::unbounded());
    }

    public function testVisibilityRequiresPublishedStateAndActiveWindow(): void
    {
        $entry = ContentEntry::create(
            self::ID,
            'Welcome',
            'welcome',
            publicationWindow: new PublicationWindow(
                new DateTimeImmutable('2026-08-04T10:00:00Z'),
                new DateTimeImmutable('2026-08-04T11:00:00Z'),
            ),
        );

        self::assertFalse($entry->isVisibleAt(new DateTimeImmutable('2026-08-04T10:30:00Z')));

        $review = $entry->transition(new ExpectedVersion(1), new Workflow(), ContentStatus::Review);
        $published = $review->transition(new ExpectedVersion(2), new Workflow(), ContentStatus::Published);

        self::assertTrue($published->isVisibleAt(new DateTimeImmutable('2026-08-04T10:30:00Z')));
        self::assertFalse($published->isVisibleAt(new DateTimeImmutable('2026-08-04T11:00:00Z')));
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'Welcome');
    }

    public function testRejectsNonJsonContentData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'welcome', ['invalid' => new \stdClass()]);
    }

    public function testRejectsNonFiniteContentNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'welcome', ['invalid' => INF]);
    }

    public function testTransitionCannotBypassWorkflow(): void
    {
        $entry = ContentEntry::create(self::ID, 'Welcome', 'welcome');

        $this->expectException(InvalidWorkflowTransition::class);

        $entry->transition(new ExpectedVersion(1), new Workflow(), ContentStatus::Published);
    }
}
