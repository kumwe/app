<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Workflow\Application;

use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Workflow\Application\ContentTransitionAuthorizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentTransitionAuthorizer::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
#[UsesClass(InsufficientCapability::class)]
final class ContentTransitionAuthorizerTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    #[DataProvider('transitionCapabilities')]
    public function testMapsWorkflowTransitionsToLeastPrivilegeCapability(
        ContentStatus $from,
        ContentStatus $to,
        string $capability,
    ): void {
        self::assertSame(
            $capability,
            (new ContentTransitionAuthorizer())->requiredCapability($from, $to)->value(),
        );
    }

    #[DataProvider('transitionCapabilities')]
    public function testAllowsOnlyPrincipalWithRequiredTransitionCapability(
        ContentStatus $from,
        ContentStatus $to,
        string $capability,
    ): void {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, [$capability]);

        (new ContentTransitionAuthorizer())->assertAllowed($principal, $from, $to);

        self::addToAssertionCount(1);
    }

    public function testReportsExactMissingCapability(): void
    {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, ['content.read']);

        try {
            (new ContentTransitionAuthorizer())->assertAllowed(
                $principal,
                ContentStatus::Review,
                ContentStatus::Published,
            );
            self::fail('A principal without content.publish was allowed to publish.');
        } catch (InsufficientCapability $exception) {
            self::assertSame('content.publish', $exception->capability);
        }
    }

    /** @return iterable<string, array{ContentStatus, ContentStatus, string}> */
    public static function transitionCapabilities(): iterable
    {
        yield 'submit draft for review' => [ContentStatus::Draft, ContentStatus::Review, 'content.submit'];
        yield 'return review to draft' => [ContentStatus::Review, ContentStatus::Draft, 'content.review'];
        yield 'publish approved content' => [ContentStatus::Review, ContentStatus::Published, 'content.publish'];
        yield 'unpublish content' => [ContentStatus::Published, ContentStatus::Draft, 'content.unpublish'];
        yield 'archive content' => [ContentStatus::Published, ContentStatus::Archived, 'content.archive'];
        yield 'restore archived content' => [ContentStatus::Archived, ContentStatus::Draft, 'content.restore'];
    }
}
