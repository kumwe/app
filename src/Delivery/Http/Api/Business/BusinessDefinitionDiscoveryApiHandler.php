<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Publishes policy-filtered generated-business discovery through the shared surface catalog.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionDiscoveryApiHandler implements RequestHandlerInterface
{
    /**
     * Bind discovery to the shared metadata source and failure contract.
     *
     * @param  BusinessSurfaceCatalog      $catalog    Policy-filtered delivery-neutral metadata source.
     * @param  BusinessRecordApiResponder  $responder  Stable non-enumerating problem mapper.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceCatalog $catalog,
        private BusinessRecordApiResponder $responder,
    ) {
    }

    /**
     * Discover every visible definition or inspect one exact visible definition.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request.
     *
     * @return  ResponseInterface  No-store policy-filtered metadata or a stable problem document.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) !== 'GET') {
                throw new InvalidArgumentException('Business definition discovery supports only GET.');
            }
            $context = ApiExecutionContext::fromRequest($request);
            $identifier = $request->getAttribute('definition');
            $data = is_string($identifier) && $identifier !== ''
                ? $this->catalog->definition(
                    $context,
                    BusinessSurface::Api,
                    $identifier,
                    BusinessSurfaceOperation::Read,
                )
                : $this->catalog->definitions(
                    $context,
                    BusinessSurface::Api,
                    BusinessSurfaceOperation::Discover,
                );

            return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
