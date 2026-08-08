<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Api;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentApiRequest;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The REST surface for schema-plan inspection, approval and execution.
 *
 * The administrator screens, the CLI and MCP all drive BusinessSchemaService, so the same
 * checksum binding, high-impact step-up and recovery-evidence rules apply here without
 * being restated. This adapter never issues DDL and never composes a plan itself.
 */
final readonly class BusinessSchemaApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private BusinessSchemaService $schema,
        private BusinessSchemaApiPresenter $presenter,
        private BusinessApiResponder $responder,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $planId = $this->attribute($request, 'planId');
            $action = $this->action($request, $planId);

            if ($planId === null) {
                return match ([$method, $action]) {
                    ['GET', null] => $this->json(['items' => array_map(
                        $this->presenter->plan(...),
                        $this->schema->plans($context),
                    )]),
                    ['GET', 'definitions'] => $this->json(['items' => $this->schema->definitions($context)]),
                    ['POST', null] => $this->created($this->schema->createPlan(
                        $context,
                        $this->definitionId($request),
                    )),
                    ['POST', 'purge'] => $this->purge($request, $context),
                    default => throw new InvalidArgumentException('The requested plan operation is not supported.'),
                };
            }

            return match ([$method, $action]) {
                ['GET', null] => $this->json($this->planDocument($context, $planId)),
                ['POST', 'approve'] => $this->approve($request, $context, $planId),
                ['POST', 'execute'] => $this->json($this->presenter->outcome(
                    $this->schema->execute($context, $planId),
                )),
                ['POST', 'recover'] => $this->json($this->presenter->outcome(
                    $this->schema->recover($context, $planId),
                )),
                default => throw new InvalidArgumentException('The requested plan operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /** @return array<string, mixed> */
    private function planDocument(ExecutionContext $context, string $planId): array
    {
        $plan = $this->schema->plan($context, $planId);

        return [
            ...$this->presenter->plan($plan),
            'steps' => array_map($this->presenter->step(...), $this->schema->steps($context, $planId)),
        ];
    }

    private function purge(ServerRequestInterface $request, ExecutionContext $context): ResponseInterface
    {
        // Composing a destructive plan is itself high impact: it names the tables a later
        // approval may drop, so it re-proves the caller's credential exactly as the
        // administrator screen does.
        $this->assertCurrentCredential($request, $context, 'business.schema.purge-plan');

        return $this->created($this->schema->createPurgePlan($context, $this->definitionId($request)));
    }

    private function approve(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $planId,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $expected = ContentApiRequest::requiredString($body, 'expected_checksum');
        $confirmation = $body['confirmation'] ?? null;
        $evidence = $body['recovery_evidence_id'] ?? null;
        if ($confirmation !== null && !is_string($confirmation)) {
            throw new InvalidArgumentException('The approval confirmation must be a string when supplied.');
        }
        if ($evidence !== null && !is_string($evidence)) {
            throw new InvalidArgumentException('The recovery evidence ID must be a string when supplied.');
        }
        if ($confirmation !== null) {
            $this->assertCurrentCredential($request, $context, 'business.schema.approve');
        }

        return $this->json($this->presenter->plan(
            $this->schema->approve($context, $planId, $expected, $confirmation, $evidence),
        ));
    }

    private function assertCurrentCredential(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $purpose,
    ): void {
        $body = ContentApiRequest::json($request);
        $password = $body['current_password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('This operation requires the caller\'s current password.');
        }
        $this->credentials->assertCurrentPassword($context, $password, $purpose);
    }

    private function definitionId(ServerRequestInterface $request): string
    {
        return ContentApiRequest::requiredString(ContentApiRequest::json($request), 'definition_id');
    }

    private function created(SchemaPlan $plan): ResponseInterface
    {
        return $this->json($this->presenter->plan($plan), 201, ['ETag' => '"' . $plan->checksum() . '"']);
    }

    private function attribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The action segment of the request path.
     *
     * Approve, execute and recover are independently grantable, so each has its own literal
     * route and capability; the action is read back from the path rather than routed as a
     * placeholder that would blur those grants together.
     */
    private function action(ServerRequestInterface $request, ?string $planId): ?string
    {
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        if ($planId === null) {
            $tail = $segments[3] ?? null;

            return $tail === '' ? null : $tail;
        }
        $position = array_search($planId, $segments, true);
        if ($position === false) {
            return null;
        }

        return $segments[$position + 1] ?? null;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<non-empty-string, string> $headers
     */
    private function json(array $document, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($document, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
