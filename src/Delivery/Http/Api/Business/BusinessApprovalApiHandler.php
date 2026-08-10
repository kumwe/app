<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\CMS\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves the non-enumerating generated-business approval collection and detail resources.
 *
 * Approval decisions require a fresh single-use browser step-up proof. A bearer API context cannot mint
 * or transport that session-bound proof, so this handler deliberately exposes inspection only; action
 * approval requests are created by the record action-approval resource and decisions remain on the
 * administrator or portal step-up surfaces.
 *
 * @since  2.0.0
 */
final readonly class BusinessApprovalApiHandler implements RequestHandlerInterface
{
    /**
     * Bind approval delivery to the scoped query and shared problem boundaries.
     *
     * @param  BusinessApprovalSurfaceService $approvals  Business-only live surface approval gate.
     * @param  BusinessApprovalApiPresenter   $presenter  Actor- and evidence-omitting projection.
     * @param  ProblemDetailsResponseFactory  $problems   Stable RFC 9457 response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessApprovalSurfaceService $approvals,
        private BusinessApprovalApiPresenter $presenter,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * List a bounded inbox or read one exact visible approval request.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request.
     *
     * @return  ResponseInterface  No-store approval JSON or stable problem details.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) !== 'GET') {
                throw new InvalidArgumentException('Business approval resources support only GET.');
            }
            $context = ApiExecutionContext::fromRequest($request);
            $approval = $request->getAttribute('approval');
            if ($approval !== null) {
                if (!is_string($approval)) {
                    throw new ApprovalDenied();
                }
                $detail = $this->approvals->businessDetail(
                    $context,
                    BusinessSurface::Api,
                    $approval,
                ) ?? throw new ApprovalDenied();

                return new JsonResponse($this->presenter->detail($detail), 200, ['Cache-Control' => 'no-store']);
            }
            $query = $request->getQueryParams();
            if (array_diff(array_keys($query), ['limit']) !== []) {
                throw new InvalidArgumentException('The approval query contains unsupported parameters.');
            }
            $limit = $this->limit($query['limit'] ?? null);

            return new JsonResponse(
                $this->presenter->collection($this->approvals->businessInbox(
                    $context,
                    BusinessSurface::Api,
                    $limit,
                )),
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (ApprovalDenied) {
            return $this->problems->create(
                404,
                'Approval Request Not Found',
                'The approval request was not found.',
                'urn:kumwe:problem:business-approval-not-found',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Invalid Approval Request',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * Parse the optional bounded decimal inbox limit without coercion surprises.
     *
     * @param   mixed  $value  Query-string limit or null.
     *
     * @return  int  Limit from one through one hundred.
     *
     * @throws  InvalidArgumentException  When the supplied spelling is not a canonical bounded integer.
     *
     * @since   2.0.0
     */
    private function limit(mixed $value): int
    {
        if ($value === null) {
            return 50;
        }
        if (!is_string($value) || preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $value) !== 1) {
            throw new InvalidArgumentException('An approval inbox limit must be between one and one hundred.');
        }

        return (int) $value;
    }
}
