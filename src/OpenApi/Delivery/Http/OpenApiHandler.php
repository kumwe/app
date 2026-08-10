<?php

declare(strict_types=1);

namespace Kumwe\CMS\OpenApi\Delivery\Http;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\OpenApi\Application\OpenApiContractProvider;
use Kumwe\CMS\OpenApi\Application\OpenApiContractUnavailable;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves only a verified current deterministic OpenAPI contract.
 *
 * @since  2.0.0
 */
final readonly class OpenApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the HTTP adapter to the generated contract service.
     *
     * @param  OpenApiContractProvider        $contracts  Access-aware deterministic contract source.
     * @param  ProblemDetailsResponseFactory  $problems   Stable fail-closed API problem renderer.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OpenApiContractProvider $contracts,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Return canonical JSON with a strong checksum ETag and conditional-request support.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request.
     *
     * @return  ResponseInterface  OpenAPI JSON, an empty 304, or a no-store 503 without stale contract bytes.
     *
     * @throws  InvalidArgumentException  When invoked with a method other than GET or HEAD.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new InvalidArgumentException('The OpenAPI contract supports only GET and HEAD.');
        }
        try {
            $contract = $this->contracts->contract(ApiExecutionContext::fromRequest($request));
        } catch (OpenApiContractUnavailable) {
            return $this->problems->create(
                503,
                'OpenAPI contract unavailable',
                'The current OpenAPI contract is temporarily unavailable.',
                'urn:kumwe:problem:openapi-contract-unavailable',
                (string) $request->getUri(),
            )
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Retry-After', '30');
        }
        $etag = '"' . $contract->checksum . '"';
        $headers = [
            'Content-Type' => 'application/vnd.oai.openapi+json;version=3.1',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Vary' => 'Authorization, Kumwe-Site',
            'X-Kumwe-Contract-Generation' => $contract->generation,
        ];
        if ($this->matches($request->getHeaderLine('If-None-Match'), $etag)) {
            return new Response('php://memory', 304, $headers);
        }
        $response = new Response('php://memory', 200, $headers);
        if ($method === 'GET') {
            $response->getBody()->write($contract->json);
        }

        return $response;
    }

    /**
     * Match a strong entity tag within a bounded conditional header.
     *
     * @param   string  $header  If-None-Match header value.
     * @param   string  $etag    Current strong entity tag.
     *
     * @return  bool  True for `*` or an exact listed strong tag.
     *
     * @since   2.0.0
     */
    private function matches(string $header, string $etag): bool
    {
        if ($header === '' || strlen($header) > 4096) {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            if (in_array(trim($candidate), ['*', $etag], true)) {
                return true;
            }
        }

        return false;
    }
}
