<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;
use Throwable;

/**
 * Console entry point for the site media library as `kumwe media`.
 *
 * The four actions are the administrator media screen's and the REST media operations': browse one filtered
 * page, read one asset, upload a file and delete an asset. Everything is `MediaService`'s: `content.read`,
 * `content.update` and `content.delete`, the configured size ceiling, content sniffing, storage and the
 * `media.upload` / `media.delete` audit events. An upload names an absolute path the storage copies from, so
 * the operator's file is left in place; `--filename` overrides the stored client name, which otherwise is the
 * file's base name. Results are pretty JSON on stdout; any refusal is one line on stderr and exit status 1.
 *
 * @since  2.0.0
 */
final readonly class MediaCommand implements Command
{
    /**
     * Wire the command to the media library service and the console's token authorization route.
     *
     * @param  MediaService       $media          Authorized, audited media library service.
     * @param  ConsoleAuthorizer  $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaService $media,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to reach the media actions.
     *
     * @return  string  Always `media`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'media';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  Catalogue identifier of the one-sentence summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.media.description';
    }

    /**
     * Run one media action and print its JSON result, or one error line.
     *
     * @param   list<string>  $arguments  Action first (default `list`), then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result or the failure line.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, match ($action) {
                'list', 'get' => 'content.read',
                'upload' => 'content.update',
                'delete' => 'content.delete',
                default => throw new InvalidArgumentException('Unsupported media action.'),
            });
            $result = match ($action) {
                'list' => $this->browse($context, $options),
                'get' => ($this->media->get($context, CommandInput::required($options, 'media'))
                    ?? throw new RuntimeException('The media asset was not found.'))->toArray(),
                'upload' => $this->upload($context, $options),
                'delete' => $this->delete($context, CommandInput::required($options, 'media')),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one operator-readable line and exit status 1.
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Browse one bounded page filtered exactly as the administrator screen and REST filter it.
     *
     * @param   ExecutionContext       $context  Authorized actor and site.
     * @param   array<string, string>  $options  Parsed options: optional `query`, `kind`, `page`, `per-page`.
     *
     * @return  array<string, mixed>  The page and the counters a client pages with.
     *
     * @throws  InvalidArgumentException  When a filter or bound is out of range.
     *
     * @since   2.0.0
     */
    private function browse(ExecutionContext $context, array $options): array
    {
        $search = $options['query'] ?? '';
        if (strlen($search) > 200) {
            throw new InvalidArgumentException('The media search must be at most 200 bytes.');
        }
        $kind = $options['kind'] ?? 'all';
        if (!in_array($kind, ['all', 'image', 'document'], true)) {
            throw new InvalidArgumentException('The media kind must be all, image or document.');
        }
        $page = isset($options['page']) ? CommandInput::positiveInteger($options, 'page') : 1;
        $perPage = isset($options['per-page']) ? CommandInput::positiveInteger($options, 'per-page') : 24;
        if ($page > 100000 || $perPage > 96) {
            throw new InvalidArgumentException('The media page must be at most 100000 and per-page at most 96.');
        }
        $result = $this->media->browse($context, $search, $kind, $page, $perPage);

        return [
            'items' => array_map(static fn (MediaAsset $asset): array => $asset->toArray(), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => $result->pages(),
            'per_page' => $result->perPage,
        ];
    }

    /**
     * Store one local file in the library under its base name or `--filename`.
     *
     * @param   ExecutionContext       $context  Authorized actor and site.
     * @param   array<string, string>  $options  Parsed options: required `file`, optional `filename`.
     *
     * @return  array<string, mixed>  The stored, audited asset.
     *
     * @throws  InvalidArgumentException  When the file is not a readable regular file or the service refuses it.
     *
     * @since   2.0.0
     */
    private function upload(ExecutionContext $context, array $options): array
    {
        $file = CommandInput::required($options, 'file');
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException('The media file must be a readable regular file.');
        }
        $name = $options['filename'] ?? basename($file);
        if (trim($name) === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A media upload requires a filename of at most 255 bytes.');
        }

        return $this->media->upload($context, $file, $name)->toArray();
    }

    /**
     * Delete one asset, answering the identifier the library no longer holds.
     *
     * @param   ExecutionContext  $context  Authorized actor and site.
     * @param   string            $id       Asset identifier.
     *
     * @return  array{id: string, deleted: true}  Confirmation.
     *
     * @since   2.0.0
     */
    private function delete(ExecutionContext $context, string $id): array
    {
        $this->media->delete($context, $id);

        return ['id' => $id, 'deleted' => true];
    }
}
