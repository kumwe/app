<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

/**
 * Drives the versioned business-definition catalogue from a host-authorized shell.
 *
 * Reads take content.read and mutations take content.update, matching the REST routes and
 * the administrator screens. Output is machine-readable JSON on success and a single
 * message on stderr with a non-zero exit on failure, so scripts can branch on the code.
 */
final readonly class ManageBusinessDefinitionsCommand implements Command
{
    private const READ_ACTIONS = ['list', 'get', 'draft', 'history', 'compatibility'];

    public function __construct(
        private BusinessDefinitionService $definitions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    public function name(): string
    {
        return 'business-definition';
    }

    public function description(): string
    {
        return 'List, inspect, draft, validate, publish, and retire business entity definitions.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $capability = in_array($action, self::READ_ACTIONS, true) ? 'content.read' : 'content.update';
            $context = $this->authorization->require($options, $capability);

            $result = match ($action) {
                'list' => ['items' => array_map(
                    $this->catalogEntry(...),
                    $this->definitions->catalog($context),
                )],
                'get' => $this->version($this->definitions->published(
                    $context,
                    CommandInput::required($options, 'handle'),
                    isset($options['version']) ? CommandInput::positiveInteger($options, 'version') : null,
                )),
                'draft' => $this->draft(
                    $this->definitions->draft($context, CommandInput::required($options, 'handle')),
                ),
                'history' => ['items' => array_map(
                    $this->version(...),
                    $this->definitions->history($context, CommandInput::required($options, 'handle')),
                )],
                'compatibility' => $this->definitions->previewDraft(
                    $context,
                    CommandInput::required($options, 'handle'),
                )->toArray(),
                'import' => $this->draft($this->definitions->importJson(
                    $context,
                    CommandInput::secretFile(CommandInput::required($options, 'definition-file')),
                    isset($options['expected-revision'])
                        ? CommandInput::positiveInteger($options, 'expected-revision')
                        : null,
                )),
                'validate' => $this->draft(
                    $this->definitions->validateDraft($context, CommandInput::required($options, 'handle')),
                ),
                'publish' => $this->version($this->definitions->publish(
                    $context,
                    CommandInput::required($options, 'handle'),
                    CommandInput::positiveInteger($options, 'expected-revision'),
                    ($options['confirmed'] ?? '0') === '1',
                )),
                'supersede', 'deprecate', 'reject' => $this->version($this->retire(
                    $context,
                    $action,
                    CommandInput::required($options, 'handle'),
                    CommandInput::positiveInteger($options, 'version'),
                )),
                default => throw new InvalidArgumentException('Unsupported business-definition action.'),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    private function retire(
        \Kumwe\CMS\Application\Authorization\ExecutionContext $context,
        string $action,
        string $handle,
        int $version,
    ): DefinitionVersionRecord {
        return match ($action) {
            'supersede' => $this->definitions->supersede($context, $handle, $version),
            'deprecate' => $this->definitions->deprecate($context, $handle, $version),
            default => $this->definitions->reject($context, $handle, $version),
        };
    }

    /** @return array<string, mixed> */
    private function catalogEntry(DefinitionCatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'handle' => $entry->handle,
            'site' => $entry->siteIdentifier,
            'owner' => $entry->owner->toArray(),
            'owner_active' => $entry->ownerActive,
            'draft_revision' => $entry->draftRevision,
            'published_version' => $entry->publishedVersion,
            'status' => $entry->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function draft(DefinitionDraft $draft): array
    {
        return [
            'revision' => $draft->revision,
            'checksum' => $draft->checksum,
            'updated_by' => $draft->updatedBy,
            'updated_at' => $draft->updatedAt->format(DATE_ATOM),
            'definition' => $draft->definition->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function version(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'compatibility' => $record->compatibility->toArray(),
            'definition' => $record->definition->toArray(),
        ];
    }
}
