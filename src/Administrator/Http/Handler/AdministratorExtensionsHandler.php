<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class AdministratorExtensionsHandler implements RequestHandlerInterface
{
    public function __construct(
        private ExtensionManager $extensions,
        private AdministratorRenderer $renderer,
        private string $temporaryDirectory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'GET') {
            return new HtmlResponse($this->renderer->render('extensions', [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'extensions' => $this->extensions->installed(AdministratorRequest::context($request)),
            ]), 200, ['Cache-Control' => 'no-store']);
        }

        $upload = $request->getUploadedFiles()['package'] ?? null;

        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('A successfully uploaded extension ZIP is required.');
        }

        if (!is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0700, true);
        }

        $temporary = $this->temporaryDirectory . '/extension-' . bin2hex(random_bytes(16)) . '.zip';
        $form = AdministratorRequest::form($request);

        try {
            $upload->moveTo($temporary);
            $this->extensions->install(
                $temporary,
                AdministratorRequest::context($request),
                ($form['key_id'] ?? '') === '' ? null : $form['key_id'],
                ($form['signature'] ?? '') === '' ? null : $form['signature'],
            );
        } catch (Throwable $exception) {
            return new HtmlResponse($this->renderer->render('extensions', [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'extensions' => $this->extensions->installed(AdministratorRequest::context($request)),
                'error' => $exception->getMessage(),
            ]), 422, ['Cache-Control' => 'no-store']);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new RedirectResponse('/administrator/extensions', 303);
    }
}
