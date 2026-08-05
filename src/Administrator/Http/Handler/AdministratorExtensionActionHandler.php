<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorExtensionActionHandler implements RequestHandlerInterface
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $identifier = AdministratorRequest::required($form, 'identifier');
        $context = AdministratorRequest::context($request);

        match (AdministratorRequest::required($form, 'action')) {
            'activate' => $this->extensions->activate($identifier, $context),
            'disable' => $this->extensions->disable($identifier, $context),
            'uninstall' => $this->extensions->uninstall($identifier, $context),
            default => throw new InvalidArgumentException('The extension action is not supported.'),
        };

        return new RedirectResponse('/administrator/extensions', 303);
    }
}
