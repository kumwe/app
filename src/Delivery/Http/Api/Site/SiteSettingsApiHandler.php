<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Site;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves both `/api/v1/settings` routes: the read of the site settings document and its replacement.
 *
 * One handler covers the pair because they answer with the same body — the managed settings document —
 * and differ only in whether a merge happens first. Nothing here decides policy: the route demands
 * `settings.manage`, the replacement route sits behind the idempotency middleware, and `SiteSettings`
 * validates the merged document and proves the capability again on both paths, so what is left for this
 * class is shaping the request and the refusal. Input it cannot use — a body that is not a JSON object,
 * or values the settings service rejects — becomes a 422 problem document, while a refusal by policy is
 * deliberately left to the pipeline's problem-details boundary. The document is served `no-store` on
 * both routes: settings change under an operator's hand and a cached copy would misdescribe the site.
 *
 * @since  2.0.0
 */
final readonly class SiteSettingsApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the settings port and to the factory that renders its refusals.
     *
     * @param  SiteSettings                   $settings  Reads and replaces the site's settings document.
     * @param  ProblemDetailsResponseFactory  $problems  Builds the 422 document unusable input is answered with.
     *
     * @since  2.0.0
     */
    public function __construct(private SiteSettings $settings, private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * Answer a `GET` with the managed settings document, or apply a replacement and answer with the result.
     *
     * A `GET` reads no body. Every other method is treated as the replacement: the body must decode to a
     * JSON object — a list is refused, since settings are keyed — nesting no deeper than 32 levels, and
     * an authenticated principal must be on the request, its absence meaning the route was mounted
     * without bearer authentication rather than that the caller did anything wrong. The document is read
     * back after the merge, so the caller receives what is now stored rather than an echo of what it
     * sent, defaults for untouched keys included.
     *
     * @param   ServerRequestInterface  $request  API request already past authentication and the capability check.
     *
     * @return  ResponseInterface  A 200 carrying the managed settings document, or a 422 problem document
     *          naming the input that could not be used.
     *
     * @throws  InvalidArgumentException  When a `GET` arrives without a matching execution context and
     *          authenticated principal; on the replacement path the same failure is answered as a 422.
     * @throws  \LogicException  When the replacement path finds no authenticated principal on the request.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          settings; it is left to propagate so it is answered as a refusal, not as a 422.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return new JsonResponse(
                $this->settings->managed(ApiExecutionContext::fromRequest($request)),
                200,
                ['Cache-Control' => 'no-store'],
            );
        }

        try {
            $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new InvalidArgumentException('Settings input must be a JSON object.');
            }
            /** @var array<string, mixed> $body */
            $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal) {
                throw new \LogicException('Settings mutations require an authenticated principal.');
            }
            $this->settings->updateAll(ApiExecutionContext::fromRequest($request), $body);

            return new JsonResponse(
                $this->settings->managed(ApiExecutionContext::fromRequest($request)),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (JsonException | InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Settings',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }
}
