<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Domain\TemplateOverridePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateOverridePolicy::class)]
final class TemplateOverridePolicyTest extends TestCase
{
    public function testAllowsDeclaredLogicalViewAndSafeTwigPath(): void
    {
        $policy = new TemplateOverridePolicy(['content.page']);

        self::assertSame(
            'content/page.twig',
            $policy->authorize('content.page', 'content/page.twig'),
        );
    }

    public function testRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TemplateOverridePolicy(['content.page']))
            ->authorize('content.page', '../secrets.twig');
    }

    public function testRejectsUndeclaredLogicalView(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TemplateOverridePolicy(['content.page']))
            ->authorize('administrator.users', 'administrator/users.twig');
    }
}
