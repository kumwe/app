<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Application;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Site\Application\SiteSettings;

final readonly class AnnouncementService
{
    private const CAPABILITY = 'kumwe.announcements-example.manage';

    public function __construct(private SiteSettings $settings)
    {
    }

    /** @return array{site_name: string, announcements: list<array{title: string, message: string}>} */
    public function dashboard(ExecutionContext $context): array
    {
        $principal = $context->principal();
        if ($principal === null || !$principal->hasCapability(Capability::fromString(self::CAPABILITY))) {
            throw new \InvalidArgumentException('The announcements capability is required.');
        }
        $settings = $this->settings->current();
        $siteName = $settings['site_name'] ?? 'Kumwe';
        if (!is_string($siteName) || trim($siteName) === '') {
            $siteName = 'Kumwe';
        }
        return [
            'site_name' => $siteName,
            'announcements' => [[
                'title' => 'Contribution contract active',
                'message' => 'This graphical page is supplied by a trusted component through the typed runtime SPI.',
            ]],
        ];
    }
}
