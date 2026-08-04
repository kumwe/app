<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HomePageHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Kumwe CMS</title></head><body><main><h1>Kumwe CMS 2.0</h1>'
            . '<p>The application kernel is running.</p></main></body></html>',
        );
    }
}
