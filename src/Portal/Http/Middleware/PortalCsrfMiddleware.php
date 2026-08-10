<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Middleware;

use Kumwe\CMS\Portal\Http\PortalRequest;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Independent synchronizer-token guard for portal mutations.
 *
 * @since  2.0.0
 */
final class PortalCsrfMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute carrying the original parsed body after successful CSRF validation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE_PARSED_BODY = self::class . '.parsed_body';

    /**
     * Compare the portal session token in constant time before forwarding a flattened form.
     *
     * @param   ServerRequestInterface   $request  Portal mutation.
     * @param   RequestHandlerInterface  $handler  Downstream handler.
     *
     * @return  ResponseInterface  Downstream response or a no-store 403 page.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            $parsed = [];
            parse_str((string) $request->getBody(), $parsed);
        }
        $form = PortalRequest::form($request);
        $provided = $request->getHeaderLine('X-CSRF-Token');
        if ($provided === '') {
            $provided = $form['_csrf'] ?? '';
        }
        if ($provided === '' || !hash_equals($session->csrfToken, $provided)) {
            return new HtmlResponse(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Forbidden</title></head>'
                . '<body><main><h1>Forbidden</h1><p>The portal security token is invalid or expired.</p>'
                . '<p><a href="/portal">Return to the portal</a></p></main></body></html>',
                403,
                ['Cache-Control' => 'no-store'],
            );
        }

        return $handler->handle(
            $request
                ->withParsedBody($form)
                ->withAttribute(self::ATTRIBUTE_PARSED_BODY, $parsed),
        );
    }
}
