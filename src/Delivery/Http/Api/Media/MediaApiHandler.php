<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Media;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the site media library to bearer callers through the same `MediaService` the administrator uses.
 *
 * Browse, read, upload and delete are the administrator media screen's four operations. Authorization,
 * the configured size ceiling, content sniffing, storage and the `media.upload` / `media.delete` audit events
 * all stay in `MediaService`; this adapter only turns the HTTP request into its arguments. An upload is the
 * raw file as the request body, named by the required `filename` query parameter, so the idempotency
 * fingerprint covers the exact bytes and the body limit is the same one the browser's form meets. Policy
 * refusals propagate to the pipeline's problem-details boundary; unusable input becomes a 422 document.
 *
 * @since  2.0.0
 */
final readonly class MediaApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the media library service and the shared problem factory.
     *
     * @param  MediaService                   $media               Authorized, audited media library service.
     * @param  ProblemDetailsResponseFactory  $problems            Stable RFC 9457 response factory.
     * @param  string                         $temporaryDirectory  Private directory an upload is staged in.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaService $media,
        private ProblemDetailsResponseFactory $problems,
        private string $temporaryDirectory,
    ) {
    }

    /**
     * Browse, read, upload or delete according to the matched method and route.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request past the capability pre-flight.
     *
     * @return  ResponseInterface  No-store JSON, an empty 204, or a problem document.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the media service refuses the capability.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $id = $request->getAttribute('mediaId');
        try {
            if ($method === 'GET' && is_string($id)) {
                $asset = $this->media->get(ApiExecutionContext::fromRequest($request), $id);
                if (!$asset instanceof MediaAsset) {
                    return $this->problems->create(
                        404,
                        'Media Not Found',
                        'The media asset was not found.',
                        'urn:kumwe:problem:media-not-found',
                        (string) $request->getUri(),
                    )->withHeader('Cache-Control', 'no-store');
                }

                return new JsonResponse($asset->toArray(), 200, ['Cache-Control' => 'no-store']);
            }
            if ($method === 'GET') {
                return $this->browse($request);
            }
            if ($method === 'DELETE' && is_string($id)) {
                $this->media->delete(ApiExecutionContext::fromRequest($request), $id);

                return (new EmptyResponse(204))->withHeader('Cache-Control', 'no-store');
            }
            if ($method === 'POST' && $id === null) {
                return new JsonResponse($this->upload($request)->toArray(), 201, ['Cache-Control' => 'no-store']);
            }

            throw new InvalidArgumentException('The media operation is not supported.');
        } catch (InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Media Request',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
    }

    /**
     * Answer one bounded page of the library filtered exactly as the administrator screen filters it.
     *
     * @param   ServerRequestInterface  $request  Collection request carrying `q`, `kind`, `page` and `per_page`.
     *
     * @return  ResponseInterface  The page and the counters a client pages with.
     *
     * @throws  InvalidArgumentException  When a query member is unknown or not canonical.
     *
     * @since   2.0.0
     */
    private function browse(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['q', 'kind', 'page', 'per_page']) !== []) {
            throw new InvalidArgumentException('The media query contains unsupported parameters.');
        }
        $search = $query['q'] ?? '';
        $kind = $query['kind'] ?? 'all';
        if (!is_string($search) || strlen($search) > 200) {
            throw new InvalidArgumentException('The media search must be at most 200 bytes.');
        }
        if (!is_string($kind) || !in_array($kind, ['all', 'image', 'document'], true)) {
            throw new InvalidArgumentException('The media kind must be all, image or document.');
        }
        $page = self::positive($query['page'] ?? null, 1, 100000, 'page');
        $perPage = self::positive($query['per_page'] ?? null, 24, 96, 'per_page');
        $result = $this->media->browse(ApiExecutionContext::fromRequest($request), $search, $kind, $page, $perPage);

        return new JsonResponse([
            'items' => array_map(static fn (MediaAsset $asset): array => $asset->toArray(), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => $result->pages(),
            'per_page' => $result->perPage,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Stage the raw request body privately and hand it to the media service under its client file name.
     *
     * @param   ServerRequestInterface  $request  Upload request whose body is the file and whose query names it.
     *
     * @return  MediaAsset  The stored, audited asset.
     *
     * @throws  InvalidArgumentException  When the name is missing or the service refuses the file.
     *
     * @since   2.0.0
     */
    private function upload(ServerRequestInterface $request): MediaAsset
    {
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['filename']) !== []) {
            throw new InvalidArgumentException('The media upload accepts only the filename query parameter.');
        }
        $name = $query['filename'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A media upload requires a filename of at most 255 bytes.');
        }
        if (!is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0700, true);
        }
        $temporary = $this->temporaryDirectory . '/media-' . bin2hex(random_bytes(16));
        try {
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new \RuntimeException('The media upload could not be staged.');
            }
            try {
                while (!$body->eof()) {
                    fwrite($handle, $body->read(65536));
                }
            } finally {
                fclose($handle);
            }

            return $this->media->upload(ApiExecutionContext::fromRequest($request), $temporary, $name);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Read one optional canonical positive decimal query member.
     *
     * @param   mixed   $value    Raw query value, or null when absent.
     * @param   int     $default  Value used when the member is absent.
     * @param   int     $maximum  Largest accepted value.
     * @param   string  $name     Member name for the refusal.
     *
     * @return  int  Bounded positive integer.
     *
     * @throws  InvalidArgumentException  When the value is not a canonical integer within its bounds.
     *
     * @since   2.0.0
     */
    private static function positive(mixed $value, int $default, int $maximum, string $name): int
    {
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,5}$/D', $value) !== 1 || (int) $value > $maximum) {
            throw new InvalidArgumentException(sprintf('The media %s must be between 1 and %d.', $name, $maximum));
        }

        return (int) $value;
    }
}
