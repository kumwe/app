<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\Asset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Asset::class)]
final class AssetTest extends TestCase
{
    public function testAcceptsSafeHashedAssetMetadata(): void
    {
        $asset = new Asset('site.js', 'site.abc123.js', 'sha384-YWJj', ['runtime.js']);

        self::assertSame('site.js', $asset->name());
        self::assertSame('site.abc123.js', $asset->path());
        self::assertSame('sha384-YWJj', $asset->integrity());
        self::assertSame(['runtime.js'], $asset->dependencies());
    }

    public function testRejectsRemoteOrTraversingAssetPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Asset('site.js', '../site.js');
    }
}
