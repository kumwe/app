<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Media\Application\MediaAsset;
use Kumwe\CMS\Media\Application\MediaStorage;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class MediaAssetHandler implements RequestHandlerInterface
{
    public function __construct(
        private MediaStorage $media,
        private StreamFactoryInterface $streams,
        private SiteContext $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $name = $request->getAttribute('name');
        if (!is_string($id) || !is_string($name)) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }
        $asset = $this->media->find($this->site, $id);
        if (!$asset instanceof MediaAsset || !hash_equals($asset->name, rawurldecode($name))) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }

        return new Response($this->streams->createStreamFromFile($asset->path, 'rb'), 200, [
            'Content-Type' => $asset->mimeType,
            'Content-Length' => (string) $asset->size,
            'Content-Disposition' => 'inline; filename*=UTF-8\'\'' . rawurlencode($asset->name),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
