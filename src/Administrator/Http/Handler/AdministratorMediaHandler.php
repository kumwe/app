<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Media\Application\MediaAsset;
use Kumwe\CMS\Media\Application\MediaService;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorMediaHandler implements RequestHandlerInterface
{
    public function __construct(
        private MediaService $media,
        private AdministratorRenderer $renderer,
        private string $temporaryDirectory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return $this->page($request);
        }
        $id = $request->getAttribute('id');
        if (is_string($id) && $id !== '') {
            $this->media->delete(AdministratorRequest::context($request), $id);

            return new RedirectResponse('/administrator/media?deleted=1', 303);
        }
        $upload = $request->getUploadedFiles()['media'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            return $this->page($request, 'Choose a media file to upload.', 422);
        }
        if (!is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0700, true);
        }
        $temporary = $this->temporaryDirectory . '/media-' . bin2hex(random_bytes(16));
        try {
            $upload->moveTo($temporary);
            $this->media->upload(
                AdministratorRequest::context($request),
                $temporary,
                $upload->getClientFilename() ?? 'upload',
            );
        } catch (InvalidArgumentException $failure) {
            return $this->page($request, $failure->getMessage(), 422);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new RedirectResponse('/administrator/media?uploaded=1', 303);
    }

    private function page(ServerRequestInterface $request, ?string $error = null, int $status = 200): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? $query['q'] : '';
        $kind = is_string($query['kind'] ?? null) ? $query['kind'] : 'all';
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;
        $result = $this->media->browse(AdministratorRequest::context($request), $search, $kind, $page);
        $session = AdministratorRequest::session($request);

        return new HtmlResponse($this->renderer->render('media', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'assets' => array_map(static fn (MediaAsset $asset): array => $asset->toArray(), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => $result->pages(),
            'query' => $search,
            'kind' => $kind,
            'error' => $error,
            'uploaded' => ($query['uploaded'] ?? null) === '1',
            'deleted' => ($query['deleted'] ?? null) === '1',
        ]), $status, ['Cache-Control' => 'no-store']);
    }
}
