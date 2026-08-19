<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessOperationNotFound;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishes one caller-bound business operation result without making operation identities enumerable.
 *
 * The shared application service proves actor, site, organization, policy generation, delivery surface,
 * expiry and ledger integrity before returning its already projected document. This adapter neither
 * reconstructs nor filters that result; it only turns the safe array into JSON and collapses every modeled
 * unavailable outcome onto one stable Problem Details response.
 *
 * @since  2.0.0
 */
final readonly class BusinessOperationStatusApiHandler implements RequestHandlerInterface
{
    /**
     * Route attribute carrying the caller-supplied operation identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string OPERATION_ATTRIBUTE = 'operation';

    /**
     * Bind the transport adapter to the shared status boundary and problem response factory.
     *
     * @param  BusinessOperationStatusService  $operations  Caller-bound operation status service.
     * @param  ProblemDetailsResponseFactory   $problems    Shared RFC 9457 response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessOperationStatusService $operations,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Return one completed operation result for the authenticated API actor and exact context.
     *
     * @param   ServerRequestInterface  $request  Authenticated request with a matched operation attribute.
     *
     * @return  ResponseInterface  Safe status JSON, a non-enumerating 404, or a stable validation problem.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) !== 'GET') {
                throw new InvalidArgumentException('Business operation status supports only GET.');
            }
            $operation = $request->getAttribute(self::OPERATION_ATTRIBUTE);
            if (!is_string($operation) || $operation === '') {
                throw new InvalidArgumentException('A business operation identity is required.');
            }

            return new JsonResponse(
                $this->operations->get(ApiExecutionContext::fromRequest($request), $operation),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (BusinessOperationNotFound) {
            return $this->problems->create(
                404,
                'Business Operation Not Found',
                'The business operation was not found.',
                'urn:kumwe:problem:business-operation-not-found',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return $this->problems->create(
                422,
                'Invalid Business Operation',
                'The business operation request is invalid.',
                'urn:kumwe:problem:invalid-business-operation',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
    }
}
