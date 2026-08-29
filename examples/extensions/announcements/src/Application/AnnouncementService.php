<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Application;

use Kumwe\Extension\Spi\Application\ExecutionContext;

final readonly class AnnouncementService
{
    /** @return array{site_name: string, announcements: list<array{title: string, message: string}>} */
    public function dashboard(ExecutionContext $context): array
    {
        return [
            'site_name' => $context->siteIdentifier(),
            'announcements' => [[
                'title' => 'Contribution contract active',
                'message' => 'This graphical page is supplied by a trusted component through the typed runtime SPI.',
            ]],
        ];
    }
}
