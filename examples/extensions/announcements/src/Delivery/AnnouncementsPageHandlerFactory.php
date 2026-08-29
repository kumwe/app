<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Delivery;

use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use KumweExample\Announcements\Application\AnnouncementService;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AnnouncementsPageHandlerFactory implements AdministratorRouteHandlerFactory
{
    public function __construct(private AnnouncementService $announcements)
    {
    }

    public function create(AdministratorRouteRenderer $renderer): RequestHandlerInterface
    {
        return new AnnouncementsPageHandler($this->announcements, $renderer);
    }
}
