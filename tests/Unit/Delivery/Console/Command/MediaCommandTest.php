<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\App\Delivery\Console\Command\MediaCommand;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\App\Tests\Support\ConsoleCommandFixture;
use Kumwe\App\Tests\Support\InMemoryMediaStorage;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins `kumwe media` to the administrator media screen's browse, read, upload and delete.
 *
 * The real `MediaService` runs over an in-memory store, so capability checks, the size ceiling, content
 * sniffing and the `media.upload` / `media.delete` audit events are the service's; the command's job is the
 * console grammar, the JSON shapes REST also answers, and one error line with exit status 1 for any refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(MediaCommand::class)]
final class MediaCommandTest extends TestCase
{
    /**
     * Smallest byte sequence the in-memory store sniffs as a PNG image.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PNG = "\x89PNG\r\n\x1a\nparity";

    /**
     * Console authorization fixture of the running test.
     *
     * @var    ConsoleCommandFixture
     * @since  2.0.0
     */
    private ConsoleCommandFixture $console;

    /**
     * In-memory media library.
     *
     * @var    InMemoryMediaStorage
     * @since  2.0.0
     */
    private InMemoryMediaStorage $storage;

    /**
     * Recorder the media service audits to.
     *
     * @var    RecordingAuditRecorder
     * @since  2.0.0
     */
    private RecordingAuditRecorder $audit;

    /**
     * Local file the upload cases read.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $file;

    /**
     * Build a fresh library, recorder, console fixture and upload file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->console = new ConsoleCommandFixture();
        $this->storage = new InMemoryMediaStorage();
        $this->audit = new RecordingAuditRecorder();
        $this->file = sys_get_temp_dir() . '/kumwe-media-command-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($this->file, self::PNG);
    }

    /**
     * Remove the token and upload files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->console->cleanup();
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * Upload, list, read and delete answer the REST shapes and leave the operator's file in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLibraryLifecycleMatchesTheBrowserOperations(): void
    {
        $uploaded = $this->invoke(['upload', '--file=' . $this->file, '--filename=parity.png']);
        self::assertIsArray($uploaded);
        self::assertSame('parity.png', $uploaded['name']);
        self::assertSame('image/png', $uploaded['mime_type'] ?? $uploaded['mimeType'] ?? null);
        self::assertFileExists($this->file);
        $id = $uploaded['id'];

        $page = $this->invoke(['list', '--kind=image', '--query=PARITY', '--page=1', '--per-page=10']);
        self::assertSame(1, $page['total']);
        self::assertSame(1, $page['pages']);
        self::assertSame(10, $page['per_page']);
        self::assertSame([$id], array_column($page['items'], 'id'));
        self::assertSame(0, $this->invoke(['list', '--kind=document'])['total']);
        self::assertSame($uploaded, $this->invoke(['get', '--media=' . $id]));
        self::assertSame(['id' => $id, 'deleted' => true], $this->invoke(['delete', '--media=' . $id]));
        self::assertSame(0, $this->invoke(['list'])['total']);
        self::assertSame(['media.upload', 'media.delete'], $this->audit->actions());

        $unnamed = $this->invoke(['upload', '--file=' . $this->file]);
        self::assertSame(basename($this->file), $unnamed['name']);
    }

    /**
     * Unknown actions, bad filters, missing assets, unreadable files and missing grants each fail one line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsExitOneWithOneErrorLine(): void
    {
        foreach (
            [
                [['purge'], null, 'Unsupported media action.'],
                [['list', '--kind=video'], null, 'The media kind must be all, image or document.'],
                [['list', '--query=' . str_repeat('q', 201)], null, 'The media search must be at most 200 bytes.'],
                [['list', '--per-page=97'], null, 'The media page must be at most 100000 and per-page at most 96.'],
                [['get', '--media=absent'], null, 'The media asset was not found.'],
                [['upload', '--file=/nonexistent/kumwe.png'], null, 'The media file must be a readable regular file.'],
                [
                    ['upload', '--file=' . $this->file, '--filename= '],
                    null,
                    'A media upload requires a filename of at most 255 bytes.',
                ],
                [['delete', '--media=absent'], ['content.read'], null],
            ] as [$arguments, $capabilities, $message]
        ) {
            $output = new CapturingMachineConsoleOutput();
            $status = $this->command($capabilities ?? ['content.read', 'content.update', 'content.delete'])
                ->execute([...$arguments, ...$this->console->options()], $output);

            self::assertSame(1, $status, implode(' ', $arguments));
            self::assertSame([], $output->lines);
            self::assertCount(1, $output->errors);
            if ($message !== null) {
                self::assertSame($message, $output->errors[0]);
            }
        }
        self::assertSame([], $this->audit->actions());
    }

    /**
     * Run one successful invocation and decode its JSON document.
     *
     * @param   list<string>  $arguments  Action and options before the authorization options.
     *
     * @return  array<string, mixed>  Decoded result.
     *
     * @since   2.0.0
     */
    private function invoke(array $arguments): array
    {
        $output = new CapturingMachineConsoleOutput();
        $status = $this->command(['content.read', 'content.update', 'content.delete'])
            ->execute([...$arguments, ...$this->console->options()], $output);
        self::assertSame(0, $status, implode("\n", $output->errors));
        $decoded = json_decode(implode("\n", $output->lines), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Build the command over the real media service.
     *
     * @param   list<string>  $capabilities  Capabilities the console credential holds.
     *
     * @return  MediaCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(array $capabilities): MediaCommand
    {
        return new MediaCommand(
            new MediaService(
                $this->storage,
                AuthorizationContext::gateway(),
                $this->audit,
                new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
                1_000_000,
            ),
            $this->console->authorizer($capabilities),
        );
    }
}
