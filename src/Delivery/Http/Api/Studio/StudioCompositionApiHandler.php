<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Studio;

use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the Content-model Blueprint composition the administrator composition screen reads and provisions.
 *
 * `GET /api/v1/content-types/{id}/versions/{version}/composition` answers
 * `StudioContentCompositionService::find()` — the authorized model, the host binding and the exact Blueprint
 * head with its canonical document and locked dependencies — and `POST` on the same path provisions the empty
 * schema-valid draft through `provision()`, answering the existing composition when one is already bound, as the
 * screen's redirect does. The route demands the screen's `content.read` and `studio.mode.blueprint`; the
 * service authorizes the model again and records `studio.composition.provision`. A version never provisioned, and
 * a model the caller may not read or that does not exist, is a 404, as the Studio host answers `not-found` for
 * both without disclosing which; a Blueprint locked to another published theme is the screen's 409.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the composition service and the shared problem factory.
     *
     * @param  StudioContentCompositionService  $compositions  Blueprint composition application service.
     * @param  ProblemDetailsResponseFactory    $problems      Stable RFC 9457 response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentCompositionService $compositions,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Read or provision one composition according to the method.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request past the capability pre-flight.
     *
     * @return  ResponseInterface  No-store JSON, or a 404, 409 or 422 problem document.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the service refuses the model.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $contentType = $request->getAttribute('id');
        $version = $request->getAttribute('version');
        if (
            !is_string($contentType)
            || !is_string($version)
            || preg_match('/^[1-9][0-9]{0,8}$/D', $version) !== 1
        ) {
            return $this->problems->create(
                422,
                'Invalid Composition Coordinate',
                'The Content model composition coordinate is invalid.',
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
        $context = ApiExecutionContext::fromRequest($request);
        $composition = null;
        try {
            $composition = strtoupper($request->getMethod()) === 'POST'
                ? $this->compositions->provision(
                    $context,
                    $contentType,
                    (int) $version,
                    StudioContentCompositionService::RENDERERS,
                )
                : $this->compositions->find($context, $contentType, (int) $version);
        } catch (StudioCompositionThemeMismatch) {
            return $this->problems->create(
                409,
                'Composition Theme Mismatch',
                'The Blueprint is locked to a different published theme.',
                'urn:kumwe:problem:studio-composition-theme-mismatch',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        } catch (StudioProjectionRejected) {
            // A missing, unreadable or unprojectable model answers exactly as a composition never provisioned.
        }
        if ($composition === null) {
            return $this->problems->create(
                404,
                'Composition Not Found',
                'No Blueprint composition is provisioned for this Content type version.',
                'urn:kumwe:problem:studio-composition-not-found',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }

        return new JsonResponse($composition->toArray(), 200, ['Cache-Control' => 'no-store']);
    }
}
