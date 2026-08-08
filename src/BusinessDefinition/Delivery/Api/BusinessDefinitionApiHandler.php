<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Delivery\Api;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentApiRequest;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessApiResponder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use Throwable;

/**
 * The REST surface for the versioned business-definition catalog.
 *
 * Administrator, CLI and MCP callers reach the same BusinessDefinitionService, so this
 * adapter only translates HTTP into application calls: it never validates definitions,
 * decides compatibility, or touches persistence.
 */
final readonly class BusinessDefinitionApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private BusinessDefinitionService $definitions,
        private BusinessDefinitionApiPresenter $presenter,
        private BusinessApiResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $identifier = $this->attribute($request, 'identifier');
            $action = $this->action($request, $identifier);

            if ($identifier === null) {
                if ($method !== 'GET') {
                    throw new InvalidArgumentException('The definition collection only supports GET.');
                }

                return $this->json(['items' => array_map(
                    $this->presenter->catalogEntry(...),
                    array_values(array_filter(
                        $this->definitions->catalog($context),
                        static fn (mixed $entry): bool => $entry instanceof DefinitionCatalogEntry,
                    )),
                )]);
            }

            return match ([$method, $action]) {
                ['GET', null] => $this->published($request, $identifier),
                ['GET', 'draft'] => $this->json($this->presenter->draft(
                    $this->definitions->draft($context, $identifier),
                )),
                ['GET', 'history'] => $this->json(['items' => array_map(
                    $this->presenter->version(...),
                    $this->definitions->history($context, $identifier),
                )]),
                ['GET', 'compatibility'] => $this->json($this->presenter->compatibility(
                    $this->definitions->previewDraft($context, $identifier),
                )),
                ['PUT', 'draft'] => $this->saveDraft($request, $context, $identifier),
                ['POST', 'validate'] => $this->json($this->presenter->draft(
                    $this->definitions->validateDraft($context, $identifier),
                )),
                ['POST', 'publish'] => $this->publish($request, $context, $identifier),
                ['POST', 'supersede'] => $this->lifecycle($request, $context, $identifier, 'supersede'),
                ['POST', 'deprecate'] => $this->lifecycle($request, $context, $identifier, 'deprecate'),
                ['POST', 'reject'] => $this->lifecycle($request, $context, $identifier, 'reject'),
                default => throw new InvalidArgumentException('The requested definition operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    private function published(ServerRequestInterface $request, string $identifier): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $requested = $request->getQueryParams()['version'] ?? null;
        $version = null;
        if (is_string($requested) && $requested !== '') {
            if (preg_match('/^[1-9][0-9]{0,8}$/D', $requested) !== 1) {
                throw new InvalidArgumentException('The requested definition version is invalid.');
            }
            $version = (int) $requested;
        }
        $record = $this->definitions->published($context, $identifier, $version);

        return $this->versioned($record);
    }

    private function saveDraft(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $document = $this->definitionDocument($body, $identifier);
        $draft = $this->definitions->importDraft(
            $context,
            $document,
            $this->expectedRevision($request),
        );

        return $this->json($this->presenter->draft($draft), 200, ['ETag' => '"r' . $draft->revision . '"']);
    }

    private function publish(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $expected = $this->expectedRevision($request)
            ?? $this->definitions->draft($context, $identifier)->revision;
        $record = $this->definitions->publish(
            $context,
            $identifier,
            $expected,
            ($body['confirmed'] ?? false) === true,
        );

        return $this->versioned($record, 201);
    }

    private function lifecycle(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $identifier,
        string $action,
    ): ResponseInterface {
        $body = ContentApiRequest::json($request);
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new InvalidArgumentException('A definition lifecycle change requires the target version.');
        }
        $record = match ($action) {
            'supersede' => $this->definitions->supersede($context, $identifier, $version),
            'deprecate' => $this->definitions->deprecate($context, $identifier, $version),
            default => $this->definitions->reject($context, $identifier, $version),
        };

        return $this->versioned($record);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function definitionDocument(array $body, string $identifier): array
    {
        $document = $body['definition'] ?? null;
        if ($document instanceof stdClass) {
            $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('The definition field must be a JSON object.');
        }
        /** @var array<string, mixed> $document */
        if (($document['handle'] ?? $identifier) !== $identifier) {
            throw new InvalidArgumentException('The definition handle must match the request path.');
        }
        $document['handle'] = $identifier;

        return $document;
    }

    private function expectedRevision(ServerRequestInterface $request): ?int
    {
        $header = trim($request->getHeaderLine('If-Match'));
        if ($header === '') {
            return null;
        }
        if (preg_match('/^"r([1-9][0-9]{0,8})"$/D', $header, $matches) !== 1) {
            throw new InvalidArgumentException('The If-Match header must carry a definition draft revision.');
        }

        return (int) $matches[1];
    }

    private function versioned(DefinitionVersionRecord $record, int $status = 200): ResponseInterface
    {
        return $this->json($this->presenter->version($record), $status, [
            'ETag' => '"v' . $record->definition->definitionVersion . '"',
        ]);
    }

    private function attribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The sub-resource segment following the definition handle, if any.
     *
     * Routes stay literal so each operation carries its own capability and OpenAPI entry;
     * the action is read back from the path rather than encoded as a route placeholder.
     */
    private function action(ServerRequestInterface $request, ?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        $position = array_search($identifier, $segments, true);
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
