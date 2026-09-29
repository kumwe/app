<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Diagnostics;

use InvalidArgumentException;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticated, no-store REST projection of the shared operator diagnostic reader.
 *
 * @since  2.0.0
 */
final readonly class OperatorDiagnosticsApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the shared diagnostic authority and standard input refusal renderer.
     *
     * @param  OperatorDiagnostics            $diagnostics  Authorized bounded reader.
     * @param  ProblemDetailsResponseFactory  $problems     Invalid-section response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OperatorDiagnostics $diagnostics,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Read the selected fixed section; authentication and authorization failures reach the shared boundary.
     *
     * @param   ServerRequestInterface  $request  Bearer-authenticated request.
     *
     * @return  ResponseInterface  Bounded JSON or an input validation problem.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        try {
            $section = $request->getQueryParams()['section'] ?? 'queues';
            if (!is_string($section)) {
                throw new InvalidArgumentException('The diagnostic section must be a string.');
            }

            return new JsonResponse($this->diagnostics->read($context, $section), 200, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $failure) {
            return $this->problems->create(
                422,
                'Invalid diagnostic section',
                $failure->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }
}
