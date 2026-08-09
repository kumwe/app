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

/**
 * Serves the content type and workflow REST resources, which are one resource shape under two paths.
 *
 * Both are versioned definitions answered by the same four operations — list the collection, read one,
 * create the first version, publish the next — so a single handler serves `/api/v1/content-types` and
 * `/api/v1/workflows` and works out which model is addressed from the path prefix. Every single
 * definition response carries `ETag: "v<version>"`, and that is the value a later publish must quote in
 * `If-Match`; the collection listing carries none, because a list has no one version to tag.
 *
 * @since  2.0.0
 */
final readonly class ContentModelApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the model service it delegates to and the responder that renders its failures.
     *
     * @param  ContentModelService  $models     Application service owning every read and published change.
     * @param  ContentApiResponder  $responder  Maps failures onto RFC 9457 problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentModelService $models, private ContentApiResponder $responder)
    {
    }

    /**
     * List, read, create, or publish the next version of a content type or workflow.
     *
     * The `/api/v1/workflows` path prefix selects the model and the presence of an `id` route attribute
     * separates the collection from a single definition, so nothing in the body decides which operation
     * runs. A create answers 201. A publish reads the current head first so the `If-Match` precondition
     * is judged against it, and an `allow_breaking` body flag opts into a change that would strand
     * entries already authored against the stored version. Failures are handed to the responder, which
     * answers the ones it recognises and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose path picks the model and the operation, and
     *          whose body carries the definition on a create or publish.
     *
     * @return  ResponseInterface  One definition tagged with its version, an `items` collection, or a
     *          problem document saying why the operation was refused.
     *
     * @since   2.0.0
     */
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

    /**
     * Answer with one stored definition, tagged with the version a later publish has to quote.
     *
     * @param   array<string, mixed>  $document  Definition already flattened by its own `toArray()`.
     * @param   int                   $version   Version the definition carries; becomes the entity tag.
     * @param   int                   $status    Status to answer with; 201 for a definition just created.
     *
     * @return  ResponseInterface  An uncacheable JSON response carrying the definition and its `ETag`.
     *
     * @since   2.0.0
     */
    private function definition(array $document, int $version, int $status = 200): ResponseInterface
    {
        return new JsonResponse($document, $status, [
            'ETag' => '"v' . $version . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Read a required JSON object field out of a decoded request body.
     *
     * The value is converted all the way down to associative arrays, because `ContentModelService`
     * takes a schema as an array and stores and compares it in that shape.
     *
     * @param   array<string, mixed>  $body  Decoded top-level members; nested values are still `stdClass`.
     * @param   string                $key   Name of the field to read, such as `schema`.
     *
     * @return  array<string, mixed>  The field as a recursively converted associative array.
     *
     * @throws  \InvalidArgumentException  When the field is absent or is not a JSON object.
     *
     * @since   2.0.0
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
     * Read a required JSON array of objects out of a decoded request body.
     *
     * Nothing here judges what the documents mean; `ContentModelService` maps them onto workflow states
     * and transitions and enforces the structural rules once the whole shape is assembled.
     *
     * @param   array<string, mixed>  $body  Decoded top-level members; nested values are still `stdClass`.
     * @param   string                $key   Name of the field to read, such as `states` or `transitions`.
     *
     * @return  list<array<string, mixed>>  One converted document per item, in the order submitted.
     *
     * @throws  \InvalidArgumentException  When the field is absent or not a JSON array, or an item is not
     *          an object.
     *
     * @since   2.0.0
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

    /**
     * Convert one decoded JSON object into an associative array, descending into what it holds.
     *
     * @param   stdClass  $object  Object exactly as `json_decode` produced it.
     *
     * @return  array<string, mixed>  The same members, with nested objects converted as well.
     *
     * @since   2.0.0
     */
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

    /**
     * Convert one decoded value, replacing objects with arrays wherever they occur beneath it.
     *
     * @param   mixed  $value  Member of a decoded request document, of any JSON type.
     *
     * @return  mixed  The value with every nested object turned into an associative array; a scalar or
     *          null comes back unchanged, and an array keeps its keys and its order.
     *
     * @since   2.0.0
     */
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
