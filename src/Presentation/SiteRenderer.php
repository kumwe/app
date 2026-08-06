<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\Twig\SiteTwigEnvironment;

final readonly class SiteRenderer
{
    public function __construct(private SiteTwigEnvironment $twig, private ?ViteAssetManifest $assets = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $data['site_assets'] = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/site/main.ts',
            '/assets/site.css',
        )->toArray();
        return $this->twig->render($template . '.twig', $data);
    }
}
