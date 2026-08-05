<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use JsonException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

final readonly class AdministratorContentModelsHandler implements RequestHandlerInterface
{
    public function __construct(private ContentModelService $models, private AdministratorRenderer $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $kind = AdministratorRequest::required($form, 'kind');
            $action = AdministratorRequest::required($form, 'action');
            if ($kind === 'workflow') {
                $states = $this->objectList($form['states'] ?? '', 'states');
                $transitions = $this->objectList($form['transitions'] ?? '', 'transitions');
                if ($action === 'create') {
                    $this->models->createWorkflow(
                        $context,
                        AdministratorRequest::required($form, 'handle'),
                        AdministratorRequest::required($form, 'name'),
                        $states,
                        $transitions,
                    );
                } else {
                    $this->models->updateWorkflow(
                        $context,
                        AdministratorRequest::required($form, 'id'),
                        AdministratorRequest::positiveInteger($form, 'version'),
                        AdministratorRequest::required($form, 'name'),
                        $states,
                        $transitions,
                        ($form['allow_breaking'] ?? '') === '1',
                    );
                }
            } elseif ($kind === 'content_type') {
                $schema = $this->object($form['schema'] ?? '', 'schema');
                if ($action === 'create') {
                    $this->models->createContentType(
                        $context,
                        AdministratorRequest::required($form, 'handle'),
                        AdministratorRequest::required($form, 'name'),
                        AdministratorRequest::required($form, 'workflow'),
                        $schema,
                    );
                } else {
                    $this->models->updateContentType(
                        $context,
                        AdministratorRequest::required($form, 'id'),
                        AdministratorRequest::positiveInteger($form, 'version'),
                        AdministratorRequest::required($form, 'name'),
                        AdministratorRequest::required($form, 'workflow'),
                        $schema,
                        ($form['allow_breaking'] ?? '') === '1',
                    );
                }
            } else {
                throw new \InvalidArgumentException('The content model kind is unsupported.');
            }

            return new RedirectResponse('/administrator/content-models', 303);
        }

        $session = AdministratorRequest::session($request);

        return new HtmlResponse($this->renderer->render('content-models', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'content_types' => array_map($this->contentTypeDocument(...), $this->models->contentTypes($context)),
            'workflows' => array_map($this->workflowDocument(...), $this->models->workflows($context)),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function object(string $json, string $name): array
    {
        try {
            $value = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The ' . $name . ' field is invalid JSON.', 0, $exception);
        }
        if (!$value instanceof stdClass) {
            throw new \InvalidArgumentException('The ' . $name . ' field must be a JSON object.');
        }
        return $this->normalizeObject($value);
    }

    /** @return list<array<string, mixed>> */
    private function objectList(string $json, string $name): array
    {
        try {
            $value = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The ' . $name . ' field is invalid JSON.', 0, $exception);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('The ' . $name . ' field must be a JSON array.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!$item instanceof stdClass) {
                throw new \InvalidArgumentException('Every ' . $name . ' item must be a JSON object.');
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

    /** @return array<string, mixed> */
    private function contentTypeDocument(ContentTypeDefinition $definition): array
    {
        return $definition->toArray() + [
            'schema_json' => json_encode(
                $definition->schema(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function workflowDocument(WorkflowDefinition $definition): array
    {
        $document = $definition->toArray();
        $document['states_json'] = json_encode(
            $document['states'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $document['transitions_json'] = json_encode(
            $document['transitions'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return $document;
    }
}
