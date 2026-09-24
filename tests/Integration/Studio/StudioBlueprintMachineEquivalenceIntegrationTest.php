<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command\StudioBlueprintCommand;
use Kumwe\App\Delivery\Http\Api\Studio\StudioCompositionSessionApiHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionResult;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionSession;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves an agent edits a Blueprint composition through REST, the console and MCP exactly as the composition
 * screen's Studio shell does.
 *
 * Each surface gets its own provisioned composition, opens a Blueprint session, loads the Blueprint and its
 * dependencies, saves a relabelled draft with one section root, retries the save under the same key, is refused a
 * save against the revision it just replaced, publishes and unpublishes — all through
 * `StudioMachineCompositionGateway` and the browser's Producer host — so the session document, every result, the
 * replay, the stale-revision refusal and the stored Blueprint are the same on every surface. A credential without
 * the Blueprint mode, and one without the publication grant, are refused on all three surfaces with the same
 * stable codes. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMachineCompositionGateway::class)]
#[CoversClass(StudioMachineCompositionResult::class)]
#[CoversClass(StudioMachineCompositionSession::class)]
#[CoversClass(StudioCompositionSessionApiHandler::class)]
#[CoversClass(StudioBlueprintCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(StudioHostSessionAuthority::class)]
final class StudioBlueprintMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities of a credential that may compose, publish and unpublish.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FULL = ['content.read', 'studio.mode.blueprint', 'content.publish', 'content.unpublish'];

    /**
     * Harness of the running test.
     *
     * @var    ?MachineSurfaceHarness
     * @since  2.0.0
     */
    private ?MachineSurfaceHarness $harness = null;

    /**
     * Revoke every token the running test issued.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->harness?->cleanup();
        $this->harness = null;
    }

    /**
     * Open, load, list, save, replay, stale save, publish and unpublish match on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceEditsTheBlueprintLikeTheCompositionScreen(): void
    {
        [$container, $harness] = $this->boot();
        $traces = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $token = $harness->token($surface, self::FULL);
            [$type, $head] = self::provisioned($container, $surface);
            $session = $this->open($harness, $surface, $token, $type);
            self::assertTrue($session['ok'], $surface . ' could not open: ' . json_encode($session));
            $document = $session['value'];
            $reference = ['id' => $document['artifact']['id'], 'version' => $document['artifact']['version']];
            $call = fn (string $operation, mixed $argument, ?string $expected = null, ?string $key = null): array
                => $this->operation($harness, $surface, $token, $document, $operation, $argument, $expected, $key);

            $loaded = $call('load', $reference);
            $dependencies = $call('dependencies', $reference);
            self::assertTrue($loaded['ok'], $surface . ' could not load: ' . json_encode($loaded));
            // The draft is taken as objects from the store, so empty JSON objects survive the round trip.
            $draft = self::head($container, $type);
            self::assertSame(json_decode((string) json_encode($draft), true), $loaded['value']['value']);
            $draft->label->defaultMessage = 'Machine composition ' . $surface;
            $draft->roots = [(object) [
                'authoring' => (object) ['mode' => 'structural'],
                'bindings' => new \stdClass(),
                'id' => 'section',
                'properties' => new \stdClass(),
                'slots' => (object) ['content' => []],
                'type' => 'studio.core/section',
                'version' => '1.0.0',
            ]];
            $key = 'blueprint-parity-' . $surface . '-' . bin2hex(random_bytes(6));
            $saved = $call('save', $draft, $loaded['value']['revision'], $key);
            $replayed = $call('save', $draft, $loaded['value']['revision'], $key);
            $stale = $call('save', $draft, $loaded['value']['revision'], $key . '-stale');
            self::assertTrue($saved['ok'], $surface . ' could not save: ' . json_encode($saved));
            $published = $call('publish', $reference, $saved['value']['revision'], $key . '-publish');
            self::assertTrue($published['ok'], $surface . ' could not publish: ' . json_encode($published));
            $live = $call('load', $reference);
            $unpublished = $call('unpublish', $reference, $published['value']['revision'], $key . '-unpublish');
            $final = $call('load', $reference);

            $traces[$surface] = [
                'session' => [
                    $document['kind'],
                    $document['mode'],
                    $document['contentTypeVersion'],
                    $document['artifact']['revision'] === $head,
                    $document['permissions'],
                    $document['lifecycle'],
                    $document['operations'],
                ],
                'load' => [$loaded['value']['revision'] === $head, $loaded['value']['value']['status'] ?? null],
                'dependencies' => is_array($dependencies['value']['value'] ?? null)
                    && $dependencies['value']['value'] !== [],
                'save' => [
                    $saved['value']['value'],
                    $saved['value']['replayed'],
                    $saved['value']['revision'] !== $head,
                ],
                'replay' => [
                    $replayed['ok'],
                    $replayed['value']['replayed'] ?? null,
                    ($replayed['value']['revision'] ?? null) === $saved['value']['revision'],
                ],
                'stale' => [$stale['ok'], $stale['code']],
                'published' => [
                    $live['value']['value']['status'] ?? null,
                    $live['value']['revision'] === $published['value']['revision'],
                ],
                'unpublished' => [
                    $unpublished['ok'],
                    $final['value']['value']['status'] ?? null,
                    $final['value']['value']['label']['defaultMessage'] ?? null,
                ],
                'stored' => self::stored($container, $type),
            ];
        }

        foreach ($traces as $surface => $trace) {
            $expected = $traces['rest'];
            $expected['unpublished'][2] = 'Machine composition ' . $surface;
            $expected['stored'][1] = 'Machine composition ' . $surface;
            self::assertSame($expected, $trace, $surface . ' diverged from the composition screen.');
        }
        self::assertSame(
            ['studio-machine-composition-session', 'blueprint', 1, true],
            array_slice($traces['rest']['session'], 0, 4),
        );
        self::assertSame(['canPublish' => true, 'canUnpublish' => true], $traces['rest']['session'][5]);
        self::assertSame([true, 'draft'], $traces['rest']['load']);
        self::assertSame([null, false, true], $traces['rest']['save']);
        self::assertSame([true, true, true], $traces['rest']['replay']);
        self::assertSame([false, 'studio_authoring.conflict'], $traces['rest']['stale']);
        self::assertSame(['published', true], $traces['rest']['published']);
        self::assertSame([true, 'draft', 'Machine composition rest'], $traces['rest']['unpublished']);
        self::assertSame(['draft', 'Machine composition rest'], $traces['rest']['stored']);
    }

    /**
     * No Blueprint mode refuses the session, and no publication grant refuses publishing, on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsMatchOnEverySurface(): void
    {
        [$container, $harness] = $this->boot();
        $refusals = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            [$type] = self::provisioned($container, 'refusal-' . $surface);
            $reader = $harness->token($surface, ['content.read']);
            $composer = $harness->token($surface, ['content.read', 'studio.mode.blueprint']);
            $denied = $this->open($harness, $surface, $reader, $type);
            $session = $this->open($harness, $surface, $composer, $type);
            self::assertTrue($session['ok'], $surface . ' could not open: ' . json_encode($session));
            $document = $session['value'];
            $reference = ['id' => $document['artifact']['id'], 'version' => $document['artifact']['version']];
            $publish = $this->operation(
                $harness,
                $surface,
                $composer,
                $document,
                'publish',
                $reference,
                $document['artifact']['revision'],
                'blueprint-parity-denied-' . bin2hex(random_bytes(6)),
            );
            $unknown = '018f22e2-7c8b-7ab0-8f3a-' . bin2hex(random_bytes(6));
            $missing = $this->open($harness, $surface, $composer, $unknown);
            $refusals[$surface] = [
                'mode' => [$denied['ok'], $denied['code']],
                'lifecycle' => $document['lifecycle'],
                'publish' => [$publish['ok'], $publish['code']],
                'missing' => [$missing['ok'], $missing['code']],
            ];
        }

        self::assertSame($refusals['rest'], $refusals['cli']);
        self::assertSame($refusals['rest'], $refusals['mcp']);
        self::assertSame([
            'mode' => [false, 'studio_authoring.forbidden'],
            'lifecycle' => ['canPublish' => false, 'canUnpublish' => false],
            'publish' => [false, 'studio_authoring.forbidden'],
            'missing' => [false, 'studio_authoring.not_found'],
        ], $refusals['rest']);
    }

    /**
     * Boot the kernel and a harness bound to it.
     *
     * @return  array{Container, MachineSurfaceHarness}  Kernel and harness.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->harness = new MachineSurfaceHarness($container, 'blueprint-parity');

        return [$container, $this->harness];
    }

    /**
     * Open one Blueprint session on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $type     Content type UUID, always at version one.
     *
     * @return  array{ok: bool, value: mixed, code: ?string}  Session document, or the stable refusal code.
     *
     * @since   2.0.0
     */
    private function open(MachineSurfaceHarness $harness, string $surface, string $token, string $type): array
    {
        return match ($surface) {
            'rest' => self::rest($harness->rest($token, 'POST', '/api/v1/studio/composition/sessions', [
                'content_type_id' => $type,
                'content_type_version' => 1,
            ])),
            'cli' => self::cli($harness->cli(StudioBlueprintCommand::class, $token, [
                'open',
                '--content-type=' . $type,
                '--content-type-version=1',
            ])),
            default => self::mcp($harness->mcp($token, 'kumwe_studio_blueprint_open', [
                'contentType' => $type,
                'contentTypeVersion' => 1,
            ]), true),
        };
    }

    /**
     * Perform one artifact operation on one surface.
     *
     * @param   MachineSurfaceHarness  $harness    Harness.
     * @param   string                 $surface    `rest`, `cli` or `mcp`.
     * @param   string                 $token      Surface token.
     * @param   array<string, mixed>   $session    Opened session document.
     * @param   string                 $operation  Operation name.
     * @param   mixed                  $argument   Artifact reference or Blueprint document.
     * @param   ?string                $expected   Expected revision of a mutation.
     * @param   ?string                $key        Replay key of a mutation.
     *
     * @return  array{ok: bool, value: mixed, code: ?string}  `{operation, replayed, value, revision}`, or the code.
     *
     * @since   2.0.0
     */
    private function operation(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        array $session,
        string $operation,
        mixed $argument,
        ?string $expected,
        ?string $key,
    ): array {
        $json = json_encode($argument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($surface === 'rest') {
            $body = [
                'session' => $session['session'],
                'session_generation' => $session['sessionGeneration'],
                'argument' => $argument,
            ];
            if ($expected !== null) {
                $body['expected_revision'] = $expected;
            }

            return self::rest($harness->rest(
                $token,
                'POST',
                '/api/v1/studio/composition/' . $operation,
                $body,
                $key === null ? [] : ['Idempotency-Key' => $key],
            ));
        }
        if ($surface === 'cli') {
            $arguments = [
                $operation,
                '--session=' . $session['session'],
                '--session-generation=' . $session['sessionGeneration'],
                '--argument-file=' . $harness->protectedFile($json),
            ];
            if ($expected !== null) {
                $arguments[] = '--expected-revision=' . $expected;
            }
            if ($key !== null) {
                $arguments[] = '--operation-id=' . $key;
            }
            $run = self::cli($harness->cli(StudioBlueprintCommand::class, $token, $arguments));
            if ($run['ok'] && is_array($run['value'])) {
                $run['value'] = [
                    'operation' => $operation,
                    'replayed' => $run['meta']['replayed'] ?? null,
                    ...$run['value'],
                ];
            }
            unset($run['meta']);

            return $run;
        }
        $arguments = [
            'session' => $session['session'],
            'sessionGeneration' => $session['sessionGeneration'],
            'document' => $json,
        ];
        if ($expected !== null) {
            $arguments['expectedRevision'] = $expected;
        }
        if ($key !== null) {
            $arguments['operationId'] = $key;
        }
        $call = self::mcp($harness->mcp($token, 'kumwe_studio_blueprint_' . $operation, $arguments), false);
        if ($call['ok'] && is_array($call['value'])) {
            $inner = json_decode((string) $call['value']['document'], true, 64, JSON_THROW_ON_ERROR);
            $call['value'] = [
                'operation' => $call['value']['operation'],
                'replayed' => $call['value']['replayed'],
                ...(is_array($inner) ? $inner : []),
            ];
        }

        return $call;
    }

    /**
     * Normalize one REST response.
     *
     * @param   array{status: int, body: mixed, raw: string, headers: array<string, string>}  $response  Response.
     *
     * @return  array{ok: bool, value: mixed, code: ?string}  Outcome.
     *
     * @since   2.0.0
     */
    private static function rest(array $response): array
    {
        if ($response['status'] < 300) {
            return ['ok' => true, 'value' => $response['body'], 'code' => null];
        }
        $type = is_array($response['body']) ? (string) ($response['body']['type'] ?? '') : '';

        return [
            'ok' => false,
            'value' => $response['body'],
            'code' => str_replace(['urn:kumwe:problem:studio-authoring-', '-'], ['studio_authoring.', '_'], $type),
        ];
    }

    /**
     * Normalize one console run.
     *
     * @param   array{status: int, stdout: mixed, stderr: string}  $run  Run.
     *
     * @return  array{ok: bool, value: mixed, code: ?string, meta?: mixed}  Outcome.
     *
     * @since   2.0.0
     */
    private static function cli(array $run): array
    {
        if ($run['status'] === 0 && is_array($run['stdout'])) {
            return [
                'ok' => true,
                'value' => $run['stdout']['data'] ?? null,
                'code' => null,
                'meta' => $run['stdout']['meta'] ?? null,
            ];
        }
        $error = json_decode($run['stderr'], true);

        return [
            'ok' => false,
            'value' => $error,
            'code' => is_array($error) ? ($error['error']['code'] ?? null) : null,
        ];
    }

    /**
     * Normalize one MCP tool call.
     *
     * @param   array{error: bool, value: mixed}  $call     Tool call.
     * @param   bool                              $session  Whether the result is a session document.
     *
     * @return  array{ok: bool, value: mixed, code: ?string}  Outcome.
     *
     * @since   2.0.0
     */
    private static function mcp(array $call, bool $session): array
    {
        if ($call['error']) {
            return [
                'ok' => false,
                'value' => $call['value'],
                'code' => is_array($call['value']) ? ($call['value']['code'] ?? null) : null,
            ];
        }
        $value = $call['value'];
        if ($session && is_array($value)) {
            $value = json_decode((string) $value['document'], true, 64, JSON_THROW_ON_ERROR);
        }

        return ['ok' => true, 'value' => $value, 'code' => null];
    }

    /**
     * Publish one fresh Content type and provision its composition, returning the type and the Blueprint head.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $label      Distinguishing label.
     *
     * @return  array{string, string}  Content type UUID and the provisioned Blueprint revision.
     *
     * @since   2.0.0
     */
    private static function provisioned(Container $container, string $label): array
    {
        $models = $container->get(ContentModelService::class);
        $compositions = $container->get(StudioContentCompositionService::class);
        self::assertInstanceOf(ContentModelService::class, $models);
        self::assertInstanceOf(StudioContentCompositionService::class, $compositions);
        $administrator = TestKernelFactory::administratorContext($container);
        $type = $models->createContentType(
            $administrator,
            'blueprint-parity-' . $label . '-' . bin2hex(random_bytes(5)),
            'Blueprint parity ' . $label,
            ContentService::CORE_WORKFLOW_ID,
            [
                'type' => 'object',
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
                'additionalProperties' => false,
            ],
        )->id;
        $composition = $compositions->provision(
            $administrator,
            $type,
            1,
            StudioContentCompositionService::RENDERERS,
        );

        return [$type, $composition->blueprint->revision];
    }

    /**
     * Read the stored Blueprint head document as objects.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $type       Content type UUID.
     *
     * @return  \stdClass  Canonical Blueprint document.
     *
     * @since   2.0.0
     */
    private static function head(Container $container, string $type): \stdClass
    {
        $compositions = $container->get(StudioContentCompositionService::class);
        self::assertInstanceOf(StudioContentCompositionService::class, $compositions);
        $composition = $compositions->find(TestKernelFactory::administratorContext($container), $type, 1);
        self::assertNotNull($composition);

        return $composition->blueprint->document();
    }

    /**
     * Read the stored Blueprint status and label through the composition service.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $type       Content type UUID.
     *
     * @return  array{mixed, mixed}  Status and label of the stored head.
     *
     * @since   2.0.0
     */
    private static function stored(Container $container, string $type): array
    {
        $document = self::head($container, $type);

        return [$document->status ?? null, $document->label->defaultMessage ?? null];
    }
}
