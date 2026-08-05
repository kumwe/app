<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use Throwable;

final readonly class ContentModelApiHandler implements RequestHandlerInterface
{
    public function __construct(private ContentModelService $models, private ContentApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $workflow = str_starts_with($request->getUri()->getPath(), '/api/v1/workflows');
            $id = $request->getAttribute('id');
            $identifier = is_string($id) && $id !== '' ? $id : null;
            $method = strtoupper($request->getMethod());

            if ($method === 'GET' && $identifier === null) {
                $items = $workflow ? $this->models->workflows($context) : $this->models->contentTypes($context);

                return new JsonResponse(['items' => array_map(
                    static fn (ContentTypeDefinition|WorkflowDefinition $item): array => $item->toArray(),
                    $items,
                )], 200, ['Cache-Control' => 'no-store']);
            }

            if ($method === 'GET') {
                $item = $workflow
                    ? $this->models->workflow($context, (string) $identifier)
                    : $this->models->contentType($context, (string) $identifier);

                return $this->definition($item->toArray(), $item->version);
            }

            $body = ContentApiRequest::json($request);
            if ($method === 'POST') {
                $item = $workflow
                    ? $this->models->createWorkflow(
                        $context,
                        ContentApiRequest::requiredString($body, 'handle'),
                        ContentApiRequest::requiredString($body, 'name'),
                        $this->list($body, 'states'),
                        $this->list($body, 'transitions'),
                    )
                    : $this->models->createContentType(
                        $context,
                        ContentApiRequest::requiredString($body, 'handle'),
                        ContentApiRequest::requiredString($body, 'name'),
                        ContentApiRequest::requiredString($body, 'workflow'),
                        $this->object($body, 'schema'),
                    );

                return $this->definition($item->toArray(), $item->version, 201);
            }

            $current = $workflow
                ? $this->models->workflow($context, (string) $identifier)
                : $this->models->contentType($context, (string) $identifier);
            $expected = ContentApiRequest::expectedVersion($request, $current->version);
            $breaking = ($body['allow_breaking'] ?? false) === true;
            $item = $workflow
                ? $this->models->updateWorkflow(
                    $context,
                    (string) $identifier,
                    $expected,
                    ContentApiRequest::requiredString($body, 'name'),
                    $this->list($body, 'states'),
                    $this->list($body, 'transitions'),
                    $breaking,
                )
                : $this->models->updateContentType(
                    $context,
                    (string) $identifier,
                    $expected,
                    ContentApiRequest::requiredString($body, 'name'),
                    ContentApiRequest::requiredString($body, 'workflow'),
                    $this->object($body, 'schema'),
                    $breaking,
                );

            return $this->definition($item->toArray(), $item->version);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /** @param array<string, mixed> $document */
    private function definition(array $document, int $version, int $status = 200): ResponseInterface
    {
        return new JsonResponse($document, $status, [
            'ETag' => '"v' . $version . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function object(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!$value instanceof stdClass) {
            throw new \InvalidArgumentException('The ' . $key . ' field must be a JSON object.');
        }

        return $this->normalizeObject($value);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function list(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('The ' . $key . ' field must be a JSON array.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!$item instanceof stdClass) {
                throw new \InvalidArgumentException('Every ' . $key . ' item must be a JSON object.');
            }
            $items[] = $this->normalizeObject($item);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function normalizeObject(stdClass $object): array
    {
        $normalized = [];
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($object);
        foreach ($properties as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return $this->normalizeObject($value);
        }
        if (is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return $value;
    }
}
