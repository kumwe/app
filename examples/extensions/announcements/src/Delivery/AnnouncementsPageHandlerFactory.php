<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Delivery;

use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Contribution\AdministratorRouteHandlerFactory;
use KumweExample\Announcements\Application\AnnouncementService;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AnnouncementsPageHandlerFactory implements AdministratorRouteHandlerFactory
{
    public function __construct(private AnnouncementService $announcements)
    {
    }

    public function create(AdministratorRenderer $renderer): RequestHandlerInterface
    {
        return new AnnouncementsPageHandler($this->announcements, $renderer);
    }
}
