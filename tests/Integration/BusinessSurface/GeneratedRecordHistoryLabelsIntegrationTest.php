<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessBrowserResult;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the generated history page names every revision in business terms instead of platform identifiers.
 *
 * Revisions store stable identifiers — `create`, `relate.tags`, `document.amend`, `action.approve` — and
 * changed fields by handle. The administrator history page must present the catalogue's lifecycle wording,
 * the definition's own relationship, action and field labels, and the definition's plural label as its
 * eyebrow, while the revision items it discloses stay exactly the projection every other adapter returns.
 *
 * @since  2.0.0
 */
#[CoversClass(GeneratedBusinessBrowserController::class)]
final class GeneratedRecordHistoryLabelsIntegrationTest extends TestCase
{
    /**
     * Lifecycle and relationship revisions read as catalogue wording naming the relationship's label.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleAndRelationshipRevisionsReadInBusinessTerms(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
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
        $targets = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($targets as $offset => $targetId) {
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'History target ' . $offset],
                NeutralBusinessFixture::idempotencyKey('history-target-' . $offset . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'History owner'],
            NeutralBusinessFixture::idempotencyKey('history-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $version = 1;
        foreach ($targets as $position => $targetId) {
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $targetId,
                NeutralBusinessFixture::idempotencyKey('history-tag-' . $position . '-' . $suffix),
                $position,
            ))->version;
        }
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            [$targets[1], $targets[0]],
            NeutralBusinessFixture::idempotencyKey('history-reorder-' . $suffix),
        ))->version;
        $version = $records->unrelate(new UnrelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $targets[0],
            NeutralBusinessFixture::idempotencyKey('history-unrelate-' . $suffix),
        ))->version;
        $version = $records->update(new UpdateRecordCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            ['title' => 'History owner renamed'],
            NeutralBusinessFixture::idempotencyKey('history-update-' . $suffix),
        ))->version;
        $version = $records->archive(new ArchiveRecordCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            NeutralBusinessFixture::idempotencyKey('history-archive-' . $suffix),
        ))->version;
        $records->restore(new RestoreRecordCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            NeutralBusinessFixture::idempotencyKey('history-restore-' . $suffix),
        ));

        $history = $this->history($container, $context, $owner->handle, $ownerId);

        $items = $this->items($history);
        self::assertSame(
            ['restore', 'archive', 'update', 'unrelate.tags', 'reorder.tags', 'relate.tags', 'relate.tags', 'create'],
            array_column($items, 'operation'),
        );
        $labels = $this->labels($history, count($items));
        self::assertSame(
            [
                'Restored',
                'Archived',
                'Edited',
                'Removed from Tags',
                'Reordered Tags',
                'Added to Tags',
                'Added to Tags',
                'Created',
            ],
            array_column($labels, 'operation_label'),
        );
        self::assertSame(['Title'], $labels[2]['changed_labels'], 'A changed field is named by its label.');
        $definition = $history->data['definition'] ?? null;
        self::assertIsArray($definition);
        self::assertIsString($definition['plural_label'] ?? null);
        self::assertNotSame($owner->handle, $definition['plural_label']);
    }

    /**
     * Document and workflow revisions read as the catalogue's document wording and the action's own label.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAndActionRevisionsUseTheirOwnWording(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $lineDocument = NeutralBusinessFixture::documentLineDocument(
            NeutralBusinessFixture::DOCUMENT_SUFFIX,
            Uuid::uuid7()->toString(),
        );
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        NeutralBusinessFixture::install($container, $context, $lineDocument);
        $header = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::documentHeaderDocument(
                NeutralBusinessFixture::DOCUMENT_SUFFIX,
                Uuid::uuid7()->toString(),
                $lineHandle,
            ),
        );
        $documentId = Uuid::uuid7()->toString();
        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'History document ' . $suffix, 'total' => '1.00'],
            [new DocumentLineInput(['code' => 'history-' . $suffix, 'description' => 'Line', 'amount' => '1.00'])],
            NeutralBusinessFixture::idempotencyKey('history-document-' . $suffix),
            recordId: $documentId,
        ));
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '2.00'],
            [new DocumentLineInput(['code' => 'history-' . $suffix, 'description' => 'Line', 'amount' => '2.00'])],
            NeutralBusinessFixture::idempotencyKey('history-document-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));

        $document = $this->history($container, $context, $header->handle, $documentId);

        self::assertSame(['document.amend', 'document.create'], array_column($this->items($document), 'operation'));
        self::assertSame(
            ['Document amended', 'Document created'],
            array_column($this->labels($document, 2), 'operation_label'),
        );

        $workflowDocument = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        $workflowDocument['handle'] = 'site.default.history_action_' . $suffix;
        $workflowDocument['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'transitions' => [[
                'handle' => 'approve',
                'from' => 'draft',
                'to' => 'approved',
                'capability' => 'business.record.action',
            ]],
        ];
        $workflowDocument['actions'] = [[
            'handle' => 'approve',
            'label' => 'Approve',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'transition' => 'approve',
        ]];
        $workflow = NeutralBusinessFixture::install($container, $context, $workflowDocument);
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $workflow->handle,
            ['label' => 'History action record'],
            NeutralBusinessFixture::idempotencyKey('history-action-create-' . $suffix),
            recordId: $recordId,
        ));
        $records->action(new ExecuteRecordActionCommand(
            $context,
            $workflow->handle,
            $recordId,
            1,
            'approve',
            NeutralBusinessFixture::idempotencyKey('history-action-' . $suffix),
        ));

        $actions = $this->history($container, $context, $workflow->handle, $recordId);

        self::assertSame(['action.approve', 'create'], array_column($this->items($actions), 'operation'));
        self::assertSame(['Approve', 'Created'], array_column($this->labels($actions, 2), 'operation_label'));
    }

    /**
     * Resolve the live record service.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  BusinessRecordService  Record facade.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        return $records;
    }

    /**
     * Render one record's administrator history page through the controller.
     *
     * @param   Container         $container  Integration container.
     * @param   ExecutionContext  $context    Administrator.
     * @param   string            $handle     Definition handle.
     * @param   string            $record     Record identifier.
     *
     * @return  BusinessBrowserResult  The history page model.
     *
     * @since   2.0.0
     */
    private function history(
        Container $container,
        ExecutionContext $context,
        string $handle,
        string $record,
    ): BusinessBrowserResult {
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $history = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'GET',
            $handle,
            $record,
            ['task' => 'history'],
            [],
        );
        self::assertSame('business-history', $history->template);

        return $history;
    }

    /**
     * Read the disclosed revisions and prove each is the unlabelled projection.
     *
     * @param   BusinessBrowserResult  $history  History page model.
     *
     * @return  list<array<array-key, mixed>>  The revision items, newest first.
     *
     * @since   2.0.0
     */
    private function items(BusinessBrowserResult $history): array
    {
        $items = $history->data['items'] ?? null;
        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));
        $revisions = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertArrayNotHasKey('operation_label', $item, 'The disclosed revision projection is unchanged.');
            self::assertArrayNotHasKey('changed_labels', $item, 'The disclosed revision projection is unchanged.');
            $revisions[] = $item;
        }

        return $revisions;
    }

    /**
     * Read the labels the page carries beside its revisions.
     *
     * @param   BusinessBrowserResult  $history  History page model.
     * @param   int                    $count    Number of disclosed revisions the labels must align with.
     *
     * @return  list<array{operation_label: string, changed_labels: list<string>}>  One entry per revision.
     *
     * @since   2.0.0
     */
    private function labels(BusinessBrowserResult $history, int $count): array
    {
        $labels = $history->data['revision_labels'] ?? null;
        self::assertIsArray($labels);
        self::assertCount($count, $labels, 'Every revision carries exactly one label entry.');
        $aligned = [];
        foreach ($labels as $label) {
            self::assertIsArray($label);
            self::assertIsString($label['operation_label'] ?? null);
            $changed = $label['changed_labels'] ?? null;
            self::assertIsArray($changed);
            $names = [];
            foreach ($changed as $name) {
                self::assertIsString($name);
                $names[] = $name;
            }
            $aligned[] = ['operation_label' => $label['operation_label'], 'changed_labels' => $names];
        }

        return $aligned;
    }

    /**
     * Return a short unique fixture suffix.
     *
     * @return  string  Ten lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
    }
}
