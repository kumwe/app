<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use JsonException;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Twig\Error\Error;

final readonly class AdministratorRenderer
{
    public function __construct(
        private AdministratorTwigEnvironment $twig,
        private RecoveryAdministratorRenderer $recovery,
        private ?AdministratorNavigationRegistry $navigation = null,
        private ?ViteAssetManifest $assets = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $capabilities = $data['capabilities'] ?? [];
        if (!is_array($capabilities) || array_is_list($capabilities)) {
            $capabilities = [];
        }
        /** @var array<string, true> $capabilities */
        $navigation = ($this->navigation ?? AdministratorNavigationRegistry::core())->visible($capabilities);
        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/administrator/main.ts',
            '/assets/administrator.css',
            '/assets/administrator.js',
        );
        $data['administrator_navigation'] = $navigation;
        $data['active_navigation'] = $data['active_navigation'] ?? $this->activeNavigation($template);
        $data['administrator_assets'] = $assetEntry->toArray();
        try {
            $data['administrator_commands_json'] = json_encode(
                $navigation,
                JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Administrator navigation cannot be encoded.', 0, $exception);
        }
        try {
            return $this->twig->render($template . '.twig', $data);
        } catch (Error) {
            return $this->recovery->render($template, $data);
        }
    }

    private function activeNavigation(string $template): string
    {
        return match ($template) {
            'dashboard' => 'dashboard',
            'content-list', 'content-form' => 'content',
            'content-models' => 'models',
            'navigation' => 'navigation',
            'access-control' => 'access',
            'extensions' => 'extensions',
            'automation' => 'automation',
            'settings' => 'settings',
            'media' => 'media',
            default => '',
        };
    }
}
