<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Closure;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command\StudioAuthoringCommand;
use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringApiHandler;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringProblemMapper;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorVocabulary;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringDocuments;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringResult;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringSession;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioSessionSurfaceBinding;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticatedSurface;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves an agent performs Studio's durable authoring journey with the browser's authority on every surface.
 *
 * One journey — open from a reusable type, resolve, list, start, plan, save the item, replay it, reuse its key
 * with changed intent, save the design as a new type, and publish a new type version — is driven four ways:
 * through the application gateway directly, through REST on the real HTTP kernel, through the real console
 * kernel, and through MCP on the real `/mcp` server. Each surface authenticates with its own site-bound token.
 * The normalized outcomes must be identical, and so must the refusals for a missing Studio mode capability, a
 * stale expected revision and a target mismatch. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMachineAuthoringGateway::class)]
#[CoversClass(StudioMachineAuthoringOperation::class)]
#[CoversClass(StudioMachineAuthoringRefused::class)]
#[CoversClass(StudioMachineAuthoringResult::class)]
#[CoversClass(StudioMachineAuthoringSession::class)]
#[CoversClass(StudioSessionSurfaceBinding::class)]
#[CoversClass(StudioHostSessionAuthority::class)]
#[CoversClass(StudioProducerRequestAuthority::class)]
#[CoversClass(StudioProducerMutationBoundary::class)]
#[CoversClass(StudioAuthoringApiHandler::class)]
#[CoversClass(StudioAuthoringProblemMapper::class)]
#[CoversClass(StudioAuthoringCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(McpToolErrorVocabulary::class)]
final class StudioMachineAuthoringEquivalenceIntegrationTest extends TestCase
{
    /**
     * Capabilities a fully authorized machine token carries.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FULL = ['content.read', 'content.create', 'content.update', 'studio.mode.hybrid'];

    /**
     * The same capabilities without the Studio hybrid mode the browser mount also requires.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array WITHOUT_MODE = ['content.read', 'content.create', 'content.update'];

    /**
     * Kernel of the running test, kept so its tokens can be revoked afterwards.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private ?Container $container = null;

    /**
     * Identifiers of the tokens the running test issued.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $tokens = [];

    /**
     * Protected files written during one test, removed afterwards.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Revoke every token and remove every protected file a test issued, so repeated runs stay under quota.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->container !== null) {
            $access = $this->container->get(AccessControlService::class);
            self::assertInstanceOf(AccessControlService::class, $access);
            $administrator = TestKernelFactory::administratorContext($this->container);
            foreach ($this->tokens as $tokenId) {
                $access->revokeToken($administrator, $tokenId);
            }
        }
        $this->container = null;
        $this->tokens = [];
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    /**
     * Every surface completes the whole journey with identical outcomes, replay and changed-intent refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuthoringJourneyIsIdenticalThroughTheServiceRestCliAndMcp(): void
    {
        $container = $this->boot();
        $traces = [];
        foreach ($this->drivers($container, self::FULL) as $surface => $driver) {
            $traces[$surface] = $this->journey($container, $surface, $driver);
        }

        self::assertCount(4, $traces);
        $service = $traces['service'];
        foreach ($traces as $surface => $trace) {
            self::assertSame($service, $trace, $surface . ' diverged from the application service.');
        }
        self::assertSame('studio_authoring.idempotency_key_reused', $service['changed_intent']['code']);
        self::assertTrue($service['replay']['replayed']);
        self::assertTrue($service['replay']['same_value']);
    }

    /**
     * Missing mode capability, a stale expected revision and a target mismatch are refused identically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceRefusesMissingCapabilityStaleRevisionAndTargetMismatchIdentically(): void
    {
        $container = $this->boot();
        $refusals = [];
        $withoutMode = $this->drivers($container, self::WITHOUT_MODE);
        foreach ($this->drivers($container, self::FULL) as $surface => $driver) {
            $denied = $withoutMode[$surface]['open']('create', ContentService::CORE_PAGE_TYPE_ID);
            $open = $driver['open']('create', ContentService::CORE_PAGE_TYPE_ID);
            self::assertTrue($open['ok'], $surface . ' could not open: ' . json_encode($open));
            $session = $open['value'];
            $call = static fn (string $operation, stdClass $argument, ?string $key = null): array => $driver['call'](
                $operation,
                $session->session,
                $session->sessionGeneration,
                $argument,
                $key,
            );
            $start = $call('start', (object) [
                'targetId' => $session->targetId,
                'resourceContext' => $session->resourceContext,
                'source' => (object) ['kind' => 'from-type', 'type' => $session->type],
                'presentation' => 'inline',
            ], self::key($surface, 'refusal-start'));
            self::assertTrue($start['ok'], $surface . ' could not start: ' . json_encode($start));
            $snapshot = $start['value'];
            $expected = self::copy($snapshot->state->coordinates);
            $expected->blueprint->revision = 'stale-blueprint-revision';
            $stale = $call('plan-save', (object) [
                'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
                'kind' => 'authoring-save-intent',
                'sessionId' => $snapshot->sessionId,
                'expected' => $expected,
                'draft' => (object) ['outcome' => 'save-item', 'entry' => $snapshot->state->entry],
            ]);
            $resourceContext = self::copy($session->resourceContext);
            $resourceContext->key = 'contexts/' . str_repeat('0', 64);
            $mismatch = $call('resolve-target', (object) [
                'targetId' => $session->targetId,
                'intent' => 'create',
                'resourceContext' => $resourceContext,
                'requestedPresentation' => 'inline',
            ]);
            $refusals[$surface] = [
                'missing_capability' => self::refusal($denied),
                'stale_revision' => self::refusal($stale),
                'target_mismatch' => self::refusal($mismatch),
            ];
        }

        $service = $refusals['service'];
        self::assertSame('forbidden', $service['missing_capability']['category']);
        self::assertSame(['studio.host/session-refused'], $service['missing_capability']['diagnostics']);
        self::assertSame('conflict', $service['stale_revision']['category']);
        self::assertSame(['studio.authoring/expected-mismatch'], $service['stale_revision']['diagnostics']);
        self::assertSame('validation-failed', $service['target_mismatch']['category']);
        self::assertSame(['studio.authoring/resource-context-mismatch'], $service['target_mismatch']['diagnostics']);
        foreach ($refusals as $surface => $cases) {
            foreach ($cases as $case => $refusal) {
                self::assertSame($service[$case]['category'], $refusal['category'], $surface . ' ' . $case);
                self::assertSame($service[$case]['code'], $refusal['code'], $surface . ' ' . $case);
                if ($refusal['diagnostics'] !== null) {
                    self::assertSame(
                        $service[$case]['diagnostics'],
                        $refusal['diagnostics'],
                        $surface . ' ' . $case,
                    );
                }
            }
        }
        self::assertNull($refusals['mcp']['stale_revision']['diagnostics'], 'MCP envelopes stay closed.');
        self::assertNotNull($refusals['rest']['stale_revision']['diagnostics']);
        self::assertNotNull($refusals['cli']['stale_revision']['diagnostics']);
    }

    /**
     * Boot the kernel and revoke any equivalence token an interrupted earlier run left active.
     *
     * @return  Container  Migrated kernel.
     *
     * @since   2.0.0
     */
    private function boot(): Container
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $this->container = $container;
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $administrator = TestKernelFactory::administratorContext($container);
        foreach ($access->tokens($administrator) as $token) {
            if (
                is_string($token['id'] ?? null)
                && is_string($token['name'] ?? null)
                && str_starts_with($token['name'], 'studio-equivalence-')
                && ($token['revoked_at'] ?? null) === null
            ) {
                $access->revokeToken($administrator, $token['id']);
            }
        }

        return $container;
    }

    /**
     * Drive the full journey through one surface and reduce it to surface-independent facts.
     *
     * @param   Container                                   $container  Booted kernel.
     * @param   string                                      $surface    Surface label keeping keys and names apart.
     * @param   array{open: Closure, call: Closure}         $driver     Surface driver.
     *
     * @return  array<string, mixed>  Normalized trace.
     *
     * @since   2.0.0
     */
    private function journey(Container $container, string $surface, array $driver): array
    {
        $open = $driver['open']('create', ContentService::CORE_PAGE_TYPE_ID);
        self::assertTrue($open['ok'], $surface . ' could not open: ' . json_encode($open));
        $session = $open['value'];
        $trace = ['open' => [
            'intent' => $session->intent,
            'available_starts' => $session->availableStarts,
            'permissions' => $session->permissions,
            'operations' => $session->operations,
            'typed' => isset($session->type),
        ]];
        $call = static function (
            string $operation,
            stdClass $argument,
            ?string $key = null
        ) use (
            $driver,
            $session,
            $surface,
        ): stdClass {
            $outcome = $driver['call']($operation, $session->session, $session->sessionGeneration, $argument, $key);
            self::assertTrue($outcome['ok'], $surface . ' ' . $operation . ' refused: ' . json_encode($outcome));

            return $outcome['value'];
        };

        $resolution = $call('resolve-target', (object) [
            'targetId' => $session->targetId,
            'intent' => 'create',
            'resourceContext' => $session->resourceContext,
            'requestedPresentation' => 'inline',
        ]);
        $trace['resolve'] = [$resolution->availableStarts, $resolution->initialPresentation];
        $types = $call('list-types', (object) [
            'targetId' => $session->targetId,
            'resourceContext' => $session->resourceContext,
            'limit' => 100,
        ]);
        $trace['list_types'] = in_array(
            'content-type:' . ContentService::CORE_PAGE_TYPE_ID,
            array_map(static fn (stdClass $item): string => $item->reference->id, $types->items),
            true,
        );
        $snapshot = $call('start', (object) [
            'targetId' => $session->targetId,
            'resourceContext' => $session->resourceContext,
            'source' => (object) ['kind' => 'from-type', 'type' => $session->type],
            'presentation' => 'inline',
        ], self::key($surface, 'start'));
        $trace['start'] = [$snapshot->start->kind, $snapshot->capabilities->saveOutcomes];

        $entry = self::copy($snapshot->state->entry);
        $entry->values->title = 'Machine journey page';
        $entry->values->slug = 'machine-journey-' . $surface . '-' . bin2hex(random_bytes(4));
        $entry->values->data_body = 'Composed by an agent.';
        $itemDraft = (object) ['outcome' => 'save-item', 'entry' => $entry];
        $itemPlan = $call('plan-save', self::intent($snapshot, $snapshot->state->coordinates, $itemDraft));
        $trace['plan_item'] = self::plan($itemPlan);
        $itemRequest = self::request('authoring-save-item-request', $itemPlan, $itemDraft);
        $saved = $call('save-item', $itemRequest, self::key($surface, 'save-item'));
        $replay = $driver['call'](
            'save-item',
            $session->session,
            $session->sessionGeneration,
            $itemRequest,
            self::key($surface, 'save-item'),
        );
        self::assertTrue($replay['ok'], $surface . ' replay refused: ' . json_encode($replay));
        $trace['replay'] = [
            'replayed' => $replay['replayed'],
            'same_value' => self::json($replay['value']) === self::json($saved),
        ];
        $changed = self::copy($itemRequest);
        $changed->draft->entry->values->title = 'A different title under the same key';
        $trace['changed_intent'] = self::refusal($driver['call'](
            'save-item',
            $session->session,
            $session->sessionGeneration,
            $changed,
            self::key($surface, 'save-item'),
        ));
        $trace['changed_intent']['diagnostics'] = null;
        $returnPath = $saved->session->extensions->{'kumwe.app/return'}->path;
        self::assertMatchesRegularExpression('#^/administrator/content/[0-9a-f-]{36}/edit$#', $returnPath);
        $entryId = substr($returnPath, strlen('/administrator/content/'), 36);
        $trace['save_item'] = [$saved->outcome, $saved->session->state->entry->values->title];

        $name = 'Machine journey type ' . $surface . ' ' . bin2hex(random_bytes(3));
        $typeDraft = (object) [
            'outcome' => 'save-as-new-type',
            'label' => (object) ['key' => 'kumwe.app/machine-journey-type', 'defaultMessage' => $name],
            'authoringPolicy' => (object) [
                'modes' => ['model', 'blueprint', 'content'],
                'itemComposition' => 'denied',
            ],
            'model' => self::copy($saved->session->state->model),
            'blueprint' => self::copy($saved->session->state->blueprint),
        ];
        $typePlan = $call('plan-save', self::intent($snapshot, $saved->session->state->coordinates, $typeDraft));
        $trace['plan_type'] = self::plan($typePlan);
        $typed = $call(
            'save-as-new-type',
            self::request('authoring-save-as-new-type-request', $typePlan, $typeDraft),
            self::key($surface, 'save-as-new-type'),
        );
        $trace['save_as_new_type'] = [
            $typed->outcome,
            $typed->session->type->status,
            $typed->session->type->label->defaultMessage === $name,
        ];

        $model = self::copy($typed->session->state->model);
        $model->fields[] = self::teaserField();
        $versionDraft = (object) [
            'outcome' => 'save-new-type-version',
            'model' => $model,
            'blueprint' => self::copy($typed->session->state->blueprint),
        ];
        $versionPlan = $call(
            'plan-save',
            self::intent($snapshot, $typed->session->state->coordinates, $versionDraft),
        );
        $trace['plan_version'] = self::plan($versionPlan);
        $versioned = $call(
            'save-new-type-version',
            self::request('authoring-save-new-type-version-request', $versionPlan, $versionDraft),
            self::key($surface, 'save-new-type-version'),
        );
        $trace['save_new_type_version'] = [
            $versioned->outcome,
            $versioned->session->type->id === $typed->session->type->id,
            $versioned->session->type->version !== $typed->session->type->version,
        ];

        $administrator = TestKernelFactory::administratorContext($container);
        $content = $container->get(ContentService::class);
        $models = $container->get(ContentModelService::class);
        self::assertInstanceOf(ContentService::class, $content);
        self::assertInstanceOf(ContentModelService::class, $models);
        $record = $content->get($administrator, $entryId);
        $typeId = ContentStudioAuthoringDocuments::contentTypeId($typed->session->type->id);
        self::assertIsString($typeId);
        $successor = $models->contentType($administrator, $typeId, 2);
        $trace['stored'] = [
            $record->entry->title(),
            $record->entry->data()['body'] ?? null,
            $record->contentTypeId === $typeId,
            $record->contentTypeVersion,
            array_key_exists('teaser', $successor->schema()['properties'] ?? []),
            $successor->name === $name,
        ];

        return $trace;
    }

    /**
     * Build the four surface drivers, each authenticated by its own token with the given capabilities.
     *
     * @param   Container     $container     Booted kernel.
     * @param   list<string>  $capabilities  Capabilities every token carries.
     *
     * @return  array<string, array{open: Closure, call: Closure}>  Drivers keyed by surface.
     *
     * @since   2.0.0
     */
    private function drivers(Container $container, array $capabilities): array
    {
        return [
            'service' => $this->serviceDriver($container, $this->token($container, $capabilities, 'kumwe-http', 'api')),
            'rest' => $this->restDriver($container, $this->token($container, $capabilities, 'kumwe-http', 'api')),
            'cli' => $this->cliDriver($container, $this->token($container, $capabilities, 'kumwe-cli', 'management')),
            'mcp' => $this->mcpDriver($container, $this->token($container, $capabilities, 'kumwe-mcp', 'mcp')),
        ];
    }

    /**
     * Drive the application gateway directly under a verified API credential.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $token      Plaintext API token.
     *
     * @return  array{open: Closure, call: Closure}  Driver.
     *
     * @since   2.0.0
     */
    private function serviceDriver(Container $container, string $token): array
    {
        $gateway = $container->get(StudioMachineAuthoringGateway::class);
        $verifier = $container->get(AccessTokenVerifier::class);
        self::assertInstanceOf(StudioMachineAuthoringGateway::class, $gateway);
        self::assertInstanceOf(ScopedAccessTokenVerifier::class, $verifier);
        $context = static function () use ($verifier, $token) {
            $verified = $verifier->verifyScoped($token, 'kumwe-http', 'api', 'default');
            self::assertNotNull($verified);

            return $verified->context('service-' . bin2hex(random_bytes(8)), AuthenticatedSurface::Api);
        };

        return [
            'open' => static function (string $intent, ?string $type) use ($gateway, $context): array {
                try {
                    $session = $gateway->open(
                        $context(),
                        StudioAuthoringIntent::from($intent),
                        null,
                        $type,
                    );
                } catch (StudioMachineAuthoringRefused $refused) {
                    return self::refused($refused);
                }

                return ['ok' => true, 'value' => self::copy($session->toDocument()), 'replayed' => false];
            },
            'call' => static function (
                string $operation,
                string $session,
                string $generation,
                stdClass $argument,
                ?string $key,
            ) use (
                $gateway,
                $context
): array {
                try {
                    $result = $gateway->perform(
                        $context(),
                        StudioMachineAuthoringOperation::named($operation),
                        $session,
                        $generation,
                        self::copy($argument),
                        $key,
                    );
                } catch (StudioMachineAuthoringRefused $refused) {
                    return self::refused($refused);
                }

                return ['ok' => true, 'value' => self::copy($result->value()), 'replayed' => $result->replayed];
            },
        ];
    }

    /**
     * Drive REST through the real HTTP application and its bearer, site and idempotency middleware.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $token      Plaintext API token.
     *
     * @return  array{open: Closure, call: Closure}  Driver.
     *
     * @since   2.0.0
     */
    private function restDriver(Container $container, string $token): array
    {
        $application = $container->get(Application::class);
        self::assertInstanceOf(Application::class, $application);
        $post = static function (string $path, stdClass $body, ?string $key) use ($application, $token): array {
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://kumwe.test' . $path)
                ->withHeader('Host', 'kumwe.test')
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Kumwe-Site', 'default')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(self::json($body)));
            if ($key !== null) {
                $request = $request->withHeader('Idempotency-Key', $key);
            }
            $response = $application->handle($request);
            $decoded = json_decode((string) $response->getBody(), false, 128, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $decoded, (string) $response->getBody());
            if ($response->getStatusCode() >= 400) {
                self::assertIsString($decoded->type ?? null, (string) $response->getBody());
                $category = $decoded->studio_category ?? null;
                self::assertIsString($category, (string) $response->getBody());
                $code = $decoded->type === 'urn:kumwe:problem:studio-authoring-idempotency-key-reused'
                    ? 'studio_authoring.idempotency_key_reused'
                    : 'studio_authoring.' . str_replace('-', '_', $category);

                return [
                    'ok' => false,
                    'category' => $category,
                    'diagnostics' => $decoded->studio_diagnostics,
                    'code' => $code,
                ];
            }

            return [
                'ok' => true,
                'value' => $decoded->value ?? $decoded,
                'replayed' => $response->getHeaderLine('Idempotency-Replayed') === 'true',
            ];
        };

        return [
            'open' => static fn (string $intent, ?string $type): array => $post(
                StudioAuthoringApiHandler::PREFIX . 'sessions',
                (object) ['intent' => $intent, 'content_type_id' => $type],
                null,
            ),
            'call' => static fn (
                string $operation,
                string $session,
                string $generation,
                stdClass $argument,
                ?string $key,
            ): array => $post(
                StudioAuthoringApiHandler::PREFIX . $operation,
                (object) ['session' => $session, 'session_generation' => $generation, 'argument' => $argument],
                $key,
            ),
        ];
    }

    /**
     * Drive the console dispatcher, under the live generation-two contract, with owner-only files.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $token      Plaintext CLI management token.
     *
     * @return  array{open: Closure, call: Closure}  Driver.
     *
     * @since   2.0.0
     */
    private function cliDriver(Container $container, string $token): array
    {
        $command = $container->get(StudioAuthoringCommand::class);
        self::assertInstanceOf(StudioAuthoringCommand::class, $command);
        $tokenFile = $this->protectedFile($token);
        $run = function (array $arguments) use ($command, $tokenFile): array {
            $output = new CapturingConsoleOutput();
            $status = (new ConsoleApplication([$command], $output))->run([
                'bin/kumwe',
                'studio-authoring',
                ...$arguments,
                '--site=default',
                '--token-file=' . $tokenFile,
            ]);
            if ($status !== 0) {
                $failure = json_decode(implode("\n", $output->errors), false, 64, JSON_THROW_ON_ERROR);
                self::assertInstanceOf(stdClass::class, $failure, implode("\n", $output->errors));

                return [
                    'ok' => false,
                    'category' => $failure->error->details->category,
                    'diagnostics' => $failure->error->details->diagnostics,
                    'code' => $failure->error->code,
                    'status' => $status,
                ];
            }
            $success = json_decode(implode("\n", $output->lines), false, 128, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $success);

            return ['ok' => true, 'value' => $success->data, 'replayed' => $success->meta->replayed ?? false];
        };

        return [
            'open' => static fn (string $intent, ?string $type): array => $run(array_values(array_filter([
                'open',
                '--intent=' . $intent,
                $type === null ? null : '--content-type=' . $type,
            ]))),
            'call' => fn (
                string $operation,
                string $session,
                string $generation,
                stdClass $argument,
                ?string $key,
            ): array => $run(array_values(array_filter([
                $operation,
                '--session=' . $session,
                '--session-generation=' . $generation,
                '--argument-file=' . $this->protectedFile(self::json($argument)),
                $key === null ? null : '--operation-id=' . $key,
            ]))),
        ];
    }

    /**
     * Drive MCP through the real `/mcp` streamable HTTP server behind bearer authentication.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $token      Plaintext MCP token.
     *
     * @return  array{open: Closure, call: Closure}  Driver.
     *
     * @since   2.0.0
     */
    private function mcpDriver(Container $container, string $token): array
    {
        $application = $container->get(Application::class);
        self::assertInstanceOf(Application::class, $application);
        $session = null;
        $sequence = 1;
        $exchange = static function (
            string $method,
            array $params,
            bool $notification = false
        ) use (
            $application,
            $token,
            &$session,
            &$sequence,
        ): array {
            $message = ['jsonrpc' => '2.0'];
            if (!$notification) {
                $message['id'] = $sequence++;
            }
            $message['method'] = $method;
            if ($params !== []) {
                $message['params'] = $params;
            }
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://kumwe.test/mcp')
                ->withHeader('Host', 'kumwe.test')
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Kumwe-Site', 'default')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json, text/event-stream')
                ->withBody((new StreamFactory())->createStream(json_encode($message, JSON_THROW_ON_ERROR)));
            if ($session !== null) {
                $request = $request
                    ->withHeader('Mcp-Session-Id', $session)
                    ->withHeader('Mcp-Protocol-Version', '2025-11-25');
            }
            $response = $application->handle($request);
            $header = $response->getHeaderLine('Mcp-Session-Id');
            if ($header !== '') {
                $session = $header;
            }
            $body = (string) $response->getBody();

            return $body === '' ? [] : ['json' => json_decode($body, true, 512, JSON_THROW_ON_ERROR), 'raw' => $body];
        };
        $initialized = $exchange('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'Kumwe equivalence test', 'version' => '1.0.0'],
        ]);
        self::assertNotNull($session, 'The MCP server did not open a session: ' . ($initialized['raw'] ?? ''));
        $exchange('notifications/initialized', [], true);
        $tool = static function (string $name, array $arguments) use ($exchange): array {
            $response = $exchange('tools/call', ['name' => $name, 'arguments' => $arguments]);
            self::assertArrayHasKey('result', $response['json'] ?? [], $response['raw'] ?? '');
            $result = $response['json']['result'];
            $text = $result['content'][0]['text'] ?? null;
            self::assertIsString($text);
            if (($result['isError'] ?? false) === true) {
                $envelope = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
                self::assertIsArray($envelope);
                $code = $envelope['code'];
                self::assertIsString($code, $text);
                self::assertStringStartsWith('studio_authoring.', $code, $text);

                return [
                    'ok' => false,
                    'category' => $code === 'studio_authoring.idempotency_key_reused'
                        ? 'invalid-request'
                        : str_replace('_', '-', substr($code, strlen('studio_authoring.'))),
                    'diagnostics' => null,
                    'code' => $code,
                ];
            }
            $structured = $result['structuredContent'] ?? null;
            self::assertIsArray($structured, $text);
            $document = json_decode($structured['document'], false, 128, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $document);

            return ['ok' => true, 'value' => $document, 'replayed' => $structured['replayed'] ?? false];
        };

        return [
            'open' => static fn (string $intent, ?string $type): array => $tool(
                'kumwe_studio_authoring_open',
                $type === null ? ['intent' => $intent] : ['intent' => $intent, 'contentType' => $type],
            ),
            'call' => static function (
                string $operation,
                string $session,
                string $generation,
                stdClass $argument,
                ?string $key,
            ) use ($tool): array {
                $arguments = [
                    'session' => $session,
                    'sessionGeneration' => $generation,
                    'document' => self::json($argument),
                ];
                if ($key !== null) {
                    $arguments = ['operationId' => $key, ...$arguments];
                }

                return $tool('kumwe_studio_authoring_' . str_replace('-', '_', $operation), $arguments);
            },
        ];
    }

    /**
     * Issue one site-bound token for the integration administrator.
     *
     * @param   Container     $container     Booted kernel.
     * @param   list<string>  $capabilities  Delegated capabilities.
     * @param   string        $audience      Token audience.
     * @param   string        $purpose       Token purpose.
     *
     * @return  string  Plaintext token.
     *
     * @since   2.0.0
     */
    private function token(Container $container, array $capabilities, string $audience, string $purpose): string
    {
        $identities = $container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $issued = $identities->issueAccessToken(
            TestKernelFactory::administratorContext($container),
            TestKernelFactory::ADMINISTRATOR_EMAIL,
            'studio-equivalence-' . bin2hex(random_bytes(6)),
            $capabilities,
            null,
            $audience,
            $purpose,
        );

        $this->tokens[] = $issued['token_id'];

        return $issued['token'];
    }

    /**
     * Write one owner-only protected file.
     *
     * @param   string  $contents  File contents.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    private function protectedFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-studio-machine-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0o600));
        $this->files[] = $path;

        return $path;
    }

    /**
     * A stable per-surface idempotency key.
     *
     * @param   string  $surface  Surface label.
     * @param   string  $step     Journey step.
     *
     * @return  string  Key valid on every surface's grammar.
     *
     * @since   2.0.0
     */
    private static function key(string $surface, string $step): string
    {
        static $run = null;
        $run ??= bin2hex(random_bytes(6));

        return 'studio-' . $surface . '-' . $step . '-' . $run;
    }

    /**
     * Build one save intent.
     *
     * @param   stdClass  $snapshot  Started session snapshot.
     * @param   stdClass  $expected  Coordinates the intent claims.
     * @param   stdClass  $draft     Save draft.
     *
     * @return  stdClass  `authoring-save-intent` document.
     *
     * @since   2.0.0
     */
    private static function intent(stdClass $snapshot, stdClass $expected, stdClass $draft): stdClass
    {
        return (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'authoring-save-intent',
            'sessionId' => $snapshot->sessionId,
            'expected' => $expected,
            'draft' => $draft,
        ];
    }

    /**
     * Build one save request that accepts every consequence its plan discloses.
     *
     * @param   string    $kind   Request kind.
     * @param   stdClass  $plan   Accepted plan.
     * @param   stdClass  $draft  Save draft.
     *
     * @return  stdClass  Save request document.
     *
     * @since   2.0.0
     */
    private static function request(string $kind, stdClass $plan, stdClass $draft): stdClass
    {
        return (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => $kind,
            'plan' => (object) [
                'id' => $plan->id,
                'revision' => $plan->revision,
                'successorContext' => $plan->successorContext,
            ],
            'acceptedConsequences' => array_map(
                static fn (stdClass $consequence): string => $consequence->code,
                $plan->consequences,
            ),
            'draft' => $draft,
        ];
    }

    /**
     * Reduce one plan to the facts every surface must agree on.
     *
     * @param   stdClass  $plan  Save plan.
     *
     * @return  array{string, list<string>, list<string>, bool}  Outcome, artifacts, codes, confirmation.
     *
     * @since   2.0.0
     */
    private static function plan(stdClass $plan): array
    {
        return [
            $plan->outcome,
            $plan->affectedArtifacts,
            array_map(static fn (stdClass $consequence): string => $consequence->code, $plan->consequences),
            $plan->confirmationRequired,
        ];
    }

    /**
     * Reduce one refused outcome to category, code and diagnostics.
     *
     * @param   array<string, mixed>  $outcome  Driver outcome.
     *
     * @return  array{category: mixed, code: mixed, diagnostics: mixed}  Normalized refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(array $outcome): array
    {
        self::assertFalse($outcome['ok'], 'An expected refusal succeeded: ' . json_encode($outcome));

        return [
            'category' => $outcome['category'],
            'code' => $outcome['code'],
            'diagnostics' => $outcome['diagnostics'],
        ];
    }

    /**
     * Normalize a gateway refusal exactly as the other drivers normalize theirs.
     *
     * @param   StudioMachineAuthoringRefused  $refused  Canonical refusal.
     *
     * @return  array<string, mixed>  Driver outcome.
     *
     * @since   2.0.0
     */
    private static function refused(StudioMachineAuthoringRefused $refused): array
    {
        return [
            'ok' => false,
            'category' => $refused->category(),
            'diagnostics' => $refused->diagnosticCodes(),
            'code' => $refused->stableCode(),
        ];
    }

    /**
     * An optional teaser field for the successor model.
     *
     * @return  stdClass  Schema-valid content-model field.
     *
     * @since   2.0.0
     */
    private static function teaserField(): stdClass
    {
        return (object) [
            'id' => 'data_teaser',
            'kind' => 'string',
            'label' => (object) ['key' => 'kumwe.content/machine-teaser', 'defaultMessage' => 'Teaser'],
            'required' => false,
            'localized' => true,
            'cardinality' => 'one',
            'authoring' => (object) [
                'control' => 'studio.control/single-line-text',
                'group' => 'content',
                'order' => 10,
                'width' => 'full',
            ],
            'constraints' => (object) ['maxLength' => 255],
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) ['storage' => 'data', 'key' => 'teaser'],
            ],
        ];
    }

    /**
     * Deep-copy one document without aliasing.
     *
     * @param   stdClass  $document  Document to copy.
     *
     * @return  stdClass  Independent copy.
     *
     * @since   2.0.0
     */
    private static function copy(stdClass $document): stdClass
    {
        $copy = json_decode(self::json($document), false, 128, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $copy);

        return $copy;
    }

    /**
     * Encode one document exactly.
     *
     * @param   stdClass  $document  Document to encode.
     *
     * @return  string  Compact JSON.
     *
     * @since   2.0.0
     */
    private static function json(stdClass $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

/**
 * Console output sink that keeps stdout and stderr lines apart for assertion.
 *
 * @since  2.0.0
 */
final class CapturingConsoleOutput implements Output
{
    /**
     * Ordinary output lines.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure output lines.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Record one catalogue message identifier as ordinary output.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function message(string $identifier, array $parameters = []): void
    {
        $this->lines[] = $identifier;
    }

    /**
     * Record one catalogue message identifier as failure output.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failure(string $identifier, array $parameters = []): void
    {
        $this->errors[] = $identifier;
    }

    /**
     * Return the identifier itself in place of catalogue wording.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
     *
     * @return  string  The identifier.
     *
     * @since   2.0.0
     */
    public function text(string $identifier, array $parameters = []): string
    {
        return $identifier;
    }

    /**
     * Record one line of ordinary output.
     *
     * @param   string  $message  Line text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Record one line of failure output.
     *
     * @param   string  $message  Line text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
