<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use JsonException;
use Kumwe\CMS\Administrator\Content\ContentModelFormMapper;
use Kumwe\CMS\Administrator\Content\ContentModelFormPresenter;
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

/**
 * Serves the administrator screen where content types and workflows are authored and republished.
 *
 * The two live on one screen because a content type pins a workflow, so changing either in isolation
 * is misleading. Each can be authored two ways — a guided builder or a raw JSON field — and this
 * handler is what reconciles them: the `workflow_mode` and `schema_mode` flags select which reader
 * runs, and both readers produce the same associative arrays that `ContentModelService` validates and
 * publishes. Every render therefore emits both views of each stored definition, so an operator can
 * switch editors without either one having to reconstruct the other's state.
 *
 * @since  2.0.0
 */
final readonly class AdministratorContentModelsHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen to the model service and the two form translators it drives.
     *
     * @param  ContentModelService         $models     Reads and publishes content type and workflow versions.
     * @param  AdministratorRenderer       $renderer   Renders the `content-models` template.
     * @param  ?ContentModelFormMapper     $mapper     Reads builder fields into definitions; null uses a default.
     * @param  ?ContentModelFormPresenter  $presenter  Renders a schema back into builder fields; null defaults.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ?ContentModelFormMapper $mapper = null,
        private ?ContentModelFormPresenter $presenter = null,
    ) {
    }

    /**
     * Render the content-model screen, first publishing whatever a `POST` carries.
     *
     * The `kind` field chooses content type or workflow and `action` chooses create or update. An
     * update also carries the version the operator loaded and an explicit `allow_breaking` flag, so a
     * change that would strand stored entries is published only when that was consciously accepted. A
     * successful post always redirects, so a refresh cannot publish a second version.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  The rendered screen, or a 303 redirect back to it after publishing.
     *
     * @throws  \InvalidArgumentException  When `kind` is unknown, or a field is missing or malformed.
     * @throws  JsonException  When a stored schema or workflow document cannot be re-encoded for display.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $kind = AdministratorRequest::required($form, 'kind');
            $action = AdministratorRequest::required($form, 'action');
            if ($kind === 'workflow') {
                $states = ($form['workflow_mode'] ?? '') === 'builder'
                    ? ($this->mapper ?? new ContentModelFormMapper())->workflowStates($form)
                    : $this->objectList($form['states'] ?? '', 'states');
                $transitions = ($form['workflow_mode'] ?? '') === 'builder'
                    ? ($this->mapper ?? new ContentModelFormMapper())->workflowTransitions($form)
                    : $this->objectList($form['transitions'] ?? '', 'transitions');
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
                $schema = ($form['schema_mode'] ?? '') === 'builder'
                    ? ($this->mapper ?? new ContentModelFormMapper())->contentTypeSchema($form)
                    : $this->object($form['schema'] ?? '', 'schema');
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

    /**
     * Read one raw JSON field as a keyed document.
     *
     * Decoding into objects rather than arrays is deliberate: it is the only way to tell an empty
     * JSON object from an empty array, and a schema with no properties is legal while a JSON array is
     * not a schema at all. `normalizeObject()` then converts the tree back into arrays.
     *
     * @param   string  $json  Raw field value submitted by the JSON editor.
     * @param   string  $name  Field name, used to name the offending field in the failure message.
     *
     * @return  array<string, mixed>  The decoded document, every nested object converted to an array.
     *
     * @throws  \InvalidArgumentException  When the value is not valid JSON, or is not a JSON object.
     *
     * @since   2.0.0
     */
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

    /**
     * Read one raw JSON field as an ordered list of keyed documents.
     *
     * The list twin of `object()`, used for the workflow `states` and `transitions` fields. Every
     * element must itself be a JSON object, so a stray scalar in the array fails the whole field
     * rather than being skipped and silently dropping a state.
     *
     * @param   string  $json  Raw field value submitted by the JSON editor.
     * @param   string  $name  Field name, used to name the offending field in the failure message.
     *
     * @return  list<array<string, mixed>>  The decoded items, in the order they were submitted.
     *
     * @throws  \InvalidArgumentException  When the value is not a JSON array, or an item is not an object.
     *
     * @since   2.0.0
     */
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

    /**
     * Convert a decoded JSON object, and everything nested inside it, into associative arrays.
     *
     * @param   stdClass  $object  Object produced by decoding JSON in object mode.
     *
     * @return  array<string, mixed>  The same document with every object replaced by an array.
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
     * Convert one decoded JSON value, recursing through nested objects and arrays.
     *
     * @param   mixed  $value  Value taken from a decoded JSON tree.
     *
     * @return  mixed  The value with every nested object replaced by an array; scalars unchanged.
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

    /**
     * Present one content type in both of the shapes the screen's two editors bind to.
     *
     * The builder reads `builder_fields` and the JSON editor reads `schema_json`, both rendered from
     * the same stored schema, which is what lets an operator switch editors mid-edit.
     *
     * @param   ContentTypeDefinition  $definition  Head version of the content type to present.
     *
     * @return  array<string, mixed>  The definition's own fields plus `builder_fields` and `schema_json`.
     *
     * @throws  JsonException  When the stored schema cannot be encoded for the JSON editor.
     *
     * @since   2.0.0
     */
    private function contentTypeDocument(ContentTypeDefinition $definition): array
    {
        return $definition->toArray() + [
            'builder_fields' => ($this->presenter ?? new ContentModelFormPresenter())->fields($definition->schema()),
            'schema_json' => json_encode(
                $definition->schema(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * Present one workflow, adding the pretty-printed JSON the raw editor binds to.
     *
     * States and transitions are encoded separately because the screen edits them as two fields, and
     * the builder reads the array form of the same two keys.
     *
     * @param   WorkflowDefinition  $definition  Head version of the workflow to present.
     *
     * @return  array<string, mixed>  The definition's own fields plus `states_json` and `transitions_json`.
     *
     * @throws  JsonException  When the stored states or transitions cannot be encoded for the editor.
     *
     * @since   2.0.0
     */
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
