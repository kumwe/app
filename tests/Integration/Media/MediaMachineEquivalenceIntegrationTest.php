<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Media;

use Doctrine\DBAL\Connection;
use Kumwe\App\Delivery\Console\Command\MediaCommand;
use Kumwe\App\Delivery\Http\Api\Media\MediaApiHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves an agent manages the media library through REST, the console and MCP exactly as the media screen does.
 *
 * Each surface uploads the same image, finds it in the filtered library page, reads it and deletes it through
 * `MediaService`, so the stored asset shape, the `media.upload` / `media.delete` audit events and the refusal of
 * a credential without `content.delete` are the same on every surface. A repeated REST idempotency key and a
 * repeated MCP operation identifier replay the first upload instead of storing a second file. The same
 * assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(MediaApiHandler::class)]
#[CoversClass(MediaCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(MediaService::class)]
final class MediaMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * One-pixel PNG every surface uploads.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJ'
        . 'RU5ErkJggg==';

    /**
     * Capabilities the full media credential carries on every surface.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array MANAGER = ['content.read', 'content.update', 'content.delete'];

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
     * Upload, browse, read and delete yield the same asset shape and audit trail on every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceManagesTheLibraryLikeTheMediaScreen(): void
    {
        [$container, $harness] = $this->boot();
        $traces = [];
        foreach (['rest', 'cli', 'mcp'] as $surface) {
            $token = $harness->token($surface, self::MANAGER);
            $name = 'parity-' . $surface . '-' . bin2hex(random_bytes(4)) . '.png';
            $asset = $this->upload($harness, $surface, $token, $name, 'media-parity-' . bin2hex(random_bytes(8)));
            $id = $asset['id'];
            $listed = $this->browse($harness, $surface, $token, $name);
            $read = $this->read($harness, $surface, $token, $id);
            $deleted = $this->delete($harness, $surface, $token, $id);
            $traces[$surface] = [
                'name' => $asset['name'] === $name,
                'mime_type' => $asset['mime_type'],
                'keys' => array_keys($asset),
                'listed' => array_column($listed, 'id') === [$id],
                'read' => $read === $asset,
                'deleted' => $deleted,
                'gone' => $this->browse($harness, $surface, $token, $name) === [],
                'audit' => self::audit($container, $id),
            ];
        }

        foreach ($traces as $surface => $trace) {
            self::assertSame($traces['rest'], $trace, $surface . ' diverged from the media screen.');
        }
        self::assertSame([
            'name' => true,
            'mime_type' => 'image/png',
            'keys' => ['id', 'name', 'mime_type', 'size', 'size_label', 'created_at', 'url', 'is_image', 'deletable'],
            'listed' => true,
            'read' => true,
            'deleted' => true,
            'gone' => true,
            'audit' => ['media.upload', 'media.delete'],
        ], $traces['rest']);
    }

    /**
     * A repeated key replays the first upload, and a credential without `content.delete` is refused everywhere.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRepeatsReplayAndMissingAuthorityIsRefusedOnEverySurface(): void
    {
        [$container, $harness] = $this->boot();
        $rest = $harness->token('rest', self::MANAGER);
        $mcp = $harness->token('mcp', self::MANAGER);
        $restKey = 'media-parity-replay-' . bin2hex(random_bytes(6));
        $mcpKey = 'media-parity-replay-' . bin2hex(random_bytes(6));
        $name = 'parity-replay-' . bin2hex(random_bytes(4)) . '.png';
        $first = $this->upload($harness, 'rest', $rest, $name, $restKey);
        $again = $this->upload($harness, 'rest', $rest, $name, $restKey);
        $mcpName = 'parity-replay-mcp-' . bin2hex(random_bytes(4)) . '.png';
        $mcpFirst = $this->upload($harness, 'mcp', $mcp, $mcpName, $mcpKey);
        $mcpAgain = $this->upload($harness, 'mcp', $mcp, $mcpName, $mcpKey);

        self::assertSame($first, $again);
        self::assertSame($mcpFirst, $mcpAgain);
        self::assertCount(1, $this->browse($harness, 'rest', $rest, $name));
        self::assertCount(1, $this->browse($harness, 'rest', $rest, $mcpName));

        $readers = [
            'rest' => $harness->token('rest', ['content.read']),
            'cli' => $harness->token('cli', ['content.read']),
            'mcp' => $harness->token('mcp', ['content.read']),
        ];
        $restRefusal = $harness->rest($readers['rest'], 'DELETE', '/api/v1/media/' . $first['id'], null, [
            'Idempotency-Key' => 'media-parity-denied-' . bin2hex(random_bytes(6)),
        ]);
        $cliRefusal = $harness->cli(MediaCommand::class, $readers['cli'], ['delete', '--media=' . $first['id']]);
        $mcpRefusal = $harness->mcp($readers['mcp'], 'kumwe_media_delete', [
            'operationId' => 'media-parity-denied-' . bin2hex(random_bytes(6)),
            'media' => $first['id'],
        ]);

        self::assertSame(403, $restRefusal['status'], $restRefusal['raw']);
        self::assertSame(1, $cliRefusal['status']);
        self::assertNotSame('', $cliRefusal['stderr']);
        self::assertTrue($mcpRefusal['error']);
        self::assertIsArray($mcpRefusal['value']);
        self::assertSame('authorization.denied', $mcpRefusal['value']['code']);
        self::assertSame(['media.upload'], self::audit($container, $first['id']));

        $this->delete($harness, 'rest', $rest, $first['id']);
        $this->delete($harness, 'rest', $rest, $mcpFirst['id']);
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
        $this->harness = new MachineSurfaceHarness($container, 'media-parity');

        return [$container, $this->harness];
    }

    /**
     * Upload the fixture image on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $name     Client file name.
     * @param   string                 $key      Idempotency key or operation identifier.
     *
     * @return  array<string, mixed>  Stored asset.
     *
     * @since   2.0.0
     */
    private function upload(
        MachineSurfaceHarness $harness,
        string $surface,
        string $token,
        string $name,
        string $key,
    ): array {
        $bytes = (string) base64_decode(self::PNG, true);
        $result = match ($surface) {
            'rest' => $harness->rest($token, 'POST', '/api/v1/media?filename=' . rawurlencode($name), $bytes, [
                'Idempotency-Key' => $key,
            ])['body'],
            'cli' => $harness->cli(MediaCommand::class, $token, [
                'upload',
                '--file=' . $harness->protectedFile($bytes),
                '--filename=' . $name,
            ])['stdout'],
            default => $harness->mcp($token, 'kumwe_media_upload', [
                'operationId' => $key,
                'filename' => $name,
                'content' => self::PNG,
            ])['value'],
        };
        self::assertIsArray($result, $surface . ' did not upload.');
        self::assertIsString($result['id'] ?? null);

        return $result;
    }

    /**
     * Browse the library filtered to one file name on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $name     File name filter.
     *
     * @return  list<array<string, mixed>>  Matching assets.
     *
     * @since   2.0.0
     */
    private function browse(MachineSurfaceHarness $harness, string $surface, string $token, string $name): array
    {
        $page = match ($surface) {
            'rest' => $harness->rest($token, 'GET', '/api/v1/media?kind=image&q=' . rawurlencode($name))['body'],
            'cli' => $harness->cli(MediaCommand::class, $token, ['list', '--kind=image', '--query=' . $name])['stdout'],
            default => $harness->mcp($token, 'kumwe_media_list', ['kind' => 'image', 'query' => $name])['value'],
        };
        self::assertIsArray($page, $surface . ' did not browse.');
        self::assertIsArray($page['items'] ?? null);

        return array_values($page['items']);
    }

    /**
     * Read one asset on one surface.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $id       Asset identifier.
     *
     * @return  mixed  Asset document.
     *
     * @since   2.0.0
     */
    private function read(MachineSurfaceHarness $harness, string $surface, string $token, string $id): mixed
    {
        return match ($surface) {
            'rest' => $harness->rest($token, 'GET', '/api/v1/media/' . $id)['body'],
            'cli' => $harness->cli(MediaCommand::class, $token, ['get', '--media=' . $id])['stdout'],
            default => $harness->mcp($token, 'kumwe_media_get', ['media' => $id])['value'],
        };
    }

    /**
     * Delete one asset on one surface and report whether the surface confirmed it.
     *
     * @param   MachineSurfaceHarness  $harness  Harness.
     * @param   string                 $surface  `rest`, `cli` or `mcp`.
     * @param   string                 $token    Surface token.
     * @param   string                 $id       Asset identifier.
     *
     * @return  bool  Whether the surface confirmed the deletion.
     *
     * @since   2.0.0
     */
    private function delete(MachineSurfaceHarness $harness, string $surface, string $token, string $id): bool
    {
        $key = 'media-parity-delete-' . bin2hex(random_bytes(6));

        return match ($surface) {
            'rest' => $harness->rest($token, 'DELETE', '/api/v1/media/' . $id, null, [
                'Idempotency-Key' => $key,
            ])['status'] === 204,
            'cli' => $harness->cli(MediaCommand::class, $token, ['delete', '--media=' . $id])['stdout']
                === ['id' => $id, 'deleted' => true],
            default => $harness->mcp($token, 'kumwe_media_delete', ['operationId' => $key, 'media' => $id])['value']
                === ['id' => $id, 'deleted' => true],
        };
    }

    /**
     * Read the audit actions recorded for one asset, oldest first.
     *
     * @param   Container  $container  Kernel.
     * @param   string     $id         Asset identifier.
     *
     * @return  list<string>  Audit actions.
     *
     * @since   2.0.0
     */
    private static function audit(Container $container, string $id): array
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return array_map('strval', $database->fetchFirstColumn(sprintf(
            'SELECT action FROM %s WHERE subject_type = ? AND subject_id = ? ORDER BY position',
            $tables->quoted('audit_events'),
        ), ['media', $id]));
    }
}
