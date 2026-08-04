<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Middleware;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdministratorCsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $form = AdministratorRequest::form($request);
        $provided = $request->getHeaderLine('X-CSRF-Token');

        if ($provided === '') {
            $provided = $form['_csrf'] ?? '';
        }

        if ($provided === '' || !hash_equals($session->csrfToken, $provided)) {
            return new HtmlResponse(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Forbidden</title></head>'
                . '<body><main><h1>Forbidden</h1><p>The administrator security token is invalid or expired.</p>'
                . '<p><a href="/administrator">Return to Kumwe</a></p></main></body></html>',
                403,
                ['Cache-Control' => 'no-store'],
            );
        }

        return $handler->handle($request->withParsedBody($form));
    }
}
