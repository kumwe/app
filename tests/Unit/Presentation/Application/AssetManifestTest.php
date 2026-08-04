<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\Asset;
use Kumwe\CMS\Presentation\Application\AssetManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetManifest::class)]
#[UsesClass(Asset::class)]
final class AssetManifestTest extends TestCase
{
    public function testBuildsLocalUrlAndDeterministicDependencyOrder(): void
    {
        $manifest = new AssetManifest(
            new Asset('site.js', 'site.abc.js', dependencies: ['vendor.js', 'runtime.js']),
            new Asset('runtime.js', 'runtime.abc.js'),
            new Asset('vendor.js', 'vendor.abc.js', dependencies: ['runtime.js']),
        );

        self::assertSame('/assets/site.abc.js', $manifest->url('site.js'));
        self::assertSame(
            ['runtime.js', 'vendor.js', 'site.js'],
            array_map(
                static fn (Asset $asset): string => $asset->name(),
                $manifest->ordered(['site.js']),
            ),
        );
    }

    public function testRejectsMissingDependency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AssetManifest(new Asset('site.js', 'site.js', dependencies: ['missing.js']));
    }

    public function testRejectsDependencyCycle(): void
    {
        $manifest = new AssetManifest(
            new Asset('one.js', 'one.js', dependencies: ['two.js']),
            new Asset('two.js', 'two.js', dependencies: ['one.js']),
        );

        $this->expectException(InvalidArgumentException::class);

        $manifest->ordered(['one.js']);
    }
}
