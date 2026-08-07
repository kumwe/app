<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use JsonException;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Twig\Error\Error;

final readonly class AdministratorRenderer
{
    public function __construct(
        private AdministratorTwigEnvironment $twig,
        private RecoveryAdministratorRenderer $recovery,
        private ?AdministratorNavigationRegistry $navigation = null,
        private ?ViteAssetManifest $assets = null,
        private ?AdministratorViewRegistry $extensionViews = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $data['active_navigation'] = $data['active_navigation'] ?? $this->activeNavigation($template);
        $data = $this->sharedData($data);
        try {
            return $this->twig->render($template . '.twig', $data);
        } catch (Error) {
            return $this->recovery->render(
                $template,
                $this->sharedData($data, AdministratorNavigationRegistry::core()),
            );
        }
    }

    /** @param array<string, mixed> $data */
    public function renderExtension(string $extension, string $view, array $data = []): string
    {
        $owner = ContributionOwner::extension($extension);
        $template = $this->extensionViews?->template($owner, $view)
            ?? throw new \LogicException('The administrator extension view registry is unavailable.');
        $data['active_navigation'] ??= $view;
        return $this->renderTemplate(
            '@' . IsolatedTwigEnvironmentFactory::extensionNamespace($extension) . '/' . $template,
            $data,
        );
    }

    private function activeNavigation(string $template): string
    {
        return match ($template) {
            'dashboard' => 'core.dashboard',
            'content-list', 'content-form' => 'core.content',
            'content-models' => 'core.models',
            'business-definitions' => 'core.business-definitions',
            'navigation' => 'core.navigation',
            'access-control' => 'core.access',
            'extensions' => 'core.extensions',
            'automation' => 'core.automation',
            'settings' => 'core.settings',
            'media' => 'core.media',
            default => '',
        };
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data): string
    {
        $data = $this->sharedData($data);
        try {
            return $this->twig->render($template, $data);
        } catch (Error) {
            return $this->recovery->render(
                'extension-error',
                $this->sharedData($data, AdministratorNavigationRegistry::core()),
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sharedData(
        array $data,
        ?AdministratorNavigationRegistry $navigationRegistry = null,
    ): array {
        $capabilities = $data['capabilities'] ?? [];
        if (!is_array($capabilities) || array_is_list($capabilities)) {
            $capabilities = [];
        }
        /** @var array<string, true> $capabilities */
        $registry = $navigationRegistry ?? $this->navigation ?? AdministratorNavigationRegistry::core();
        $navigation = $registry->visible($capabilities);
        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/administrator/main.ts',
            '/assets/administrator.css',
            '/assets/administrator.js',
        );
        $data['administrator_navigation'] = $navigation;
        $data['administrator_workspaces'] = $registry->visibleWorkspaces($capabilities, $navigation);
        $data['administrator_assets'] = $assetEntry->toArray();
        try {
            $data['administrator_commands_json'] = json_encode(
                $navigation,
                JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Administrator navigation cannot be encoded.', 0, $exception);
        }
        return $data;
    }
}
