<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\App\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\BusinessQueryCounter;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\GeneratedBusinessParityOutput;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the REST, MCP and CLI machine adapters browse a page with a relationship include in a number of
 * database statements that does not grow with the page size, so no adapter adds a per-row query on top of
 * the shared service.
 *
 * Each adapter is warmed once, then measured at a one-row and a twelve-row page over the container's own
 * connection; an extra statement per row would show as eleven more on the larger page.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordApiHandler::class)]
#[CoversClass(BusinessMcpHandlers::class)]
#[CoversClass(ManageBusinessRecordsCommand::class)]
final class MachineAdapterQueryBudgetIntegrationTest extends TestCase
{
    /**
     * Temporary protected files created by the CLI invocations.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Every machine adapter keeps one statement budget from a one-row to a twelve-row page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryMachineAdapterBrowsesInAStatementCountIndependentOfPageSize(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $owner = $this->fixture($container, $context, 12);
        $api = $this->service($container, BusinessRecordApiHandler::class);
        $mcp = $this->service($container, BusinessMcpHandlers::class);
        $cli = $this->console($container, $principal);
        $token = $this->file('machine-adapter-query-budget-token');
        $adapters = [
            'rest' => function (int $pageSize) use ($api, $principal, $owner): array {
                $response = $api->handle((new ServerRequestFactory())
                    ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/records/' . $owner)
                    ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::BROWSE)
                    ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, $owner)
                    ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
                    ->withAttribute(ExecutionContextAttribute::NAME, $this->context($principal, 'api'))
                    ->withQueryParams([
                        'page_size' => (string) $pageSize,
                        'projection' => ['includes' => ['tags']],
                    ]));
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

                return $this->json((string) $response->getBody());
            },
            'mcp' => fn (int $pageSize): array => $mcp->search($this->context($principal, 'mcp'), $owner, [
                'page_size' => $pageSize,
                'projection' => ['includes' => ['tags']],
            ]),
            'cli' => function (int $pageSize) use ($cli, $owner, $token): array {
                $output = new GeneratedBusinessParityOutput();
                $exit = $cli->execute([
                    'list',
                    '--site=default',
                    '--token-file=' . $token,
                    '--definition=' . $owner,
                    '--query-file=' . $this->file(json_encode([
                        'page_size' => $pageSize,
                        'projection' => ['includes' => ['tags']],
                    ], JSON_THROW_ON_ERROR)),
                ], $output);
                self::assertSame(0, $exit, implode("\n", $output->errors));
                self::assertCount(1, $output->lines);
                $envelope = $this->json($output->lines[0]);
                self::assertIsArray($envelope['data'] ?? null);

                return $envelope['data'];
            },
        ];
        $counter = ContainerConnectionCounter::wrap($container);
        foreach ($adapters as $name => $browse) {
            $browse(1);
            [$small, $smallStatements] = $this->measure($counter, $browse, 1);
            [$large, $largeStatements] = $this->measure($counter, $browse, 12);
            self::assertCount(1, $this->items($small), $name);
            self::assertCount(12, $this->items($large), $name);
            foreach ($this->items($large) as $item) {
                self::assertIsArray($item['includes']['tags'] ?? null, $name);
                self::assertCount(1, $item['includes']['tags'], $name);
            }
            self::assertSame(
                $smallStatements,
                $largeStatements,
                sprintf('The %s adapter must not issue a statement per row or per included row.', $name),
            );
        }
    }

    /**
     * Remove the protected files the CLI read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    /**
     * Run one browse and count the statements it sent.
     *
     * @param   BusinessQueryCounter                           $counter   Container connection counter.
     * @param   callable(int): array<array-key, mixed>         $browse    Adapter invocation.
     * @param   int                                            $pageSize  Requested page size.
     *
     * @return  array{array<array-key, mixed>, int}  Adapter document and statement count.
     *
     * @since   2.0.0
     */
    private function measure(BusinessQueryCounter $counter, callable $browse, int $pageSize): array
    {
        $counter->reset();
        $document = $browse($pageSize);

        return [$document, $counter->queries()];
    }

    /**
     * Read the page items of an adapter document.
     *
     * @param   array<array-key, mixed>  $document  Adapter browse document.
     *
     * @return  list<array<array-key, mixed>>  Page items.
     *
     * @since   2.0.0
     */
    private function items(array $document): array
    {
        $items = $document['items'] ?? null;
        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));
        $rows = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Install a relationship owner and target and relate one target to each of the owner's records.
     *
     * @param   Container         $container  Real application container.
     * @param   ExecutionContext  $context    Installing administrator.
     * @param   int               $count      Owner records to create.
     *
     * @return  string  The installed owner definition handle.
     *
     * @since   2.0.0
     */
    private function fixture(Container $container, ExecutionContext $context, int $count): string
    {
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $records = $this->service($container, BusinessRecordService::class);
        for ($index = 1; $index <= $count; ++$index) {
            $targetId = Uuid::uuid7()->toString();
            $ownerId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Adapter budget target ' . $index . ' ' . $suffix],
                NeutralBusinessFixture::idempotencyKey('adapter-budget-target-' . $index . '-' . $suffix),
                recordId: $targetId,
            ));
            $records->create(new CreateRecordCommand(
                $context,
                $owner->handle,
                ['title' => 'Adapter budget owner ' . $index],
                NeutralBusinessFixture::idempotencyKey('adapter-budget-owner-' . $index . '-' . $suffix),
                recordId: $ownerId,
            ));
            $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                1,
                'tags',
                $targetId,
                NeutralBusinessFixture::idempotencyKey('adapter-budget-relate-' . $index . '-' . $suffix),
            ));
        }

        return $owner->handle;
    }

    /**
     * Bind the shared principal to one machine surface.
     *
     * @param   AuthenticatedPrincipal  $principal  Integration administrator.
     * @param   string                  $surface    `api` or `mcp`.
     *
     * @return  ExecutionContext  Bearer-token context for that surface.
     *
     * @since   2.0.0
     */
    private function context(AuthenticatedPrincipal $principal, string $surface): ExecutionContext
    {
        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'machine-adapter-budget-' . $surface,
            surface: $surface === 'api' ? AuthenticatedSurface::Api : AuthenticatedSurface::Mcp,
        );
    }

    /**
     * Construct the real CLI adapter with a verifier returning the shared integration principal.
     *
     * @param   Container               $container  Real application container.
     * @param   AuthenticatedPrincipal  $principal  Principal a protected test token resolves to.
     *
     * @return  ManageBusinessRecordsCommand  Fully wired command adapter.
     *
     * @since   2.0.0
     */
    private function console(Container $container, AuthenticatedPrincipal $principal): ManageBusinessRecordsCommand
    {
        $tokens = $this->createStub(AccessTokenVerifier::class);
        $tokens->method('verify')->willReturn($principal);

        return new ManageBusinessRecordsCommand(
            $this->service($container, BusinessRecordService::class),
            $this->service($container, BusinessSurfaceService::class),
            $this->service($container, BusinessSurfaceCatalog::class),
            $this->service($container, BusinessRecordQueryFactory::class),
            $this->service($container, BusinessRecordProjector::class),
            $this->service($container, BusinessOperationStatusService::class),
            $this->service($container, BusinessApprovalSurfaceService::class),
            new ConsoleAuthorizer($tokens),
            $this->service($container, BusinessRecordConsolePresenter::class),
            $this->service($container, BusinessConsoleFailureMapper::class),
        );
    }

    /**
     * Create one owner-only temporary file.
     *
     * @param   string  $contents  File contents.
     *
     * @return  string  Absolute protected path.
     *
     * @since   2.0.0
     */
    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-adapter-budget-');
        self::assertIsString($file);
        $this->files[] = $file;
        self::assertTrue(chmod($file, 0o600));
        self::assertNotFalse(file_put_contents($file, $contents));

        return $file;
    }

    /**
     * Decode one JSON object.
     *
     * @param   string  $encoded  JSON object bytes.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function json(string $encoded): array
    {
        $value = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Resolve one strongly typed service from the real container.
     *
     * @template T of object
     *
     * @param   Container        $container  Real application container.
     * @param   class-string<T>  $class      Requested service type.
     *
     * @return  T  Requested service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
