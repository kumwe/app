<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\SignedWorkflowExtensionFixture;
use Kumwe\Content\Domain\ContentStatus;
use Kumwe\Extension\Spi\Contribution\TranslationSetItemAssociation;
use Kumwe\Localization\Domain\LocaleTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Drive existing P7-E workflow models after genuine SDK packaging and signed extension admission.
 *
 * These are backend vertical proofs, not substitutes for the five browser journeys or Gate B. Every
 * business model is a checked-in snapshot of the existing acceptance fixture, with no weakened rules.
 * The content case reuses the already-supported contributed translation-set lifecycle.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(ContentService::class)]
final class SignedWorkflowPortfolioIntegrationTest extends TestCase
{
    /**
     * Publish a content item through review and attach its locale to the signed owner's declared set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedContentPackageOwnsAPublishedTranslation(): void
    {
        $fixture = SignedWorkflowExtensionFixture::install(
            Environment::fromGlobals(),
            'workflow/content-' . bin2hex(random_bytes(5)),
            [],
            ['en-GB', 'de'],
        );
        try {
            $content = $fixture->container->get(ContentService::class);
            self::assertInstanceOf(ContentService::class, $content);
            $page = $content->create(
                $fixture->context,
                'Contributed story',
                $fixture->keyId,
                ['body' => 'Proof story'],
            );
            foreach ([ContentStatus::Review, ContentStatus::Published] as $state) {
                $page = $content->transition($fixture->context, $page->entry->id(), $page->entry->version(), $state);
            }
            $association = new TranslationSetItemAssociation(
                $fixture->identifier,
                str_replace('/', '.', $fixture->identifier) . '.stories',
            );
            $page = $content->translateContributed(
                $fixture->context,
                $page->entry->id(),
                $page->entry->version(),
                LocaleTag::fromString('en-GB'),
                $association,
            );
            self::assertSame(ContentStatus::Published, $page->entry->status());
            self::assertSame($association->groupIdForSite('default'), $page->entry->translationGroupId());
        } finally {
            $fixture->dispose();
        }
    }

    /**
     * Review, approve and post an exact thousand-line document owned by a signed package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedExactDocumentRunsItsWholeWorkflow(): void
    {
        $fixture = $this->fixture('exact-document');
        try {
            $records = $this->records($fixture);
            $definition = $fixture->definitions['site.default.doc_header_browser'];
            $lines = [];
            for ($index = 1; $index <= 1000; ++$index) {
                $lines[] = new DocumentLineInput([
                    'code' => 'SIGNED-' . $index,
                    'description' => 'Ledger entry ' . $index,
                    'amount' => sprintf('%d.%02d', intdiv($index, 100), $index % 100),
                ]);
            }
            $command = new WriteDocumentCommand(
                $fixture->context,
                $definition->handle,
                'lines',
                ['title' => 'Signed exact document', 'total' => '5005.00'],
                $lines,
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-draft'),
                recordId: Uuid::uuid7()->toString(),
            );
            $record = $records->writeDocument($command);
            self::assertSame('draft', $record->workflowState);
            self::assertTrue($records->writeDocument($command)->replayed);
            foreach (['submit' => 'in_review', 'approve' => 'approved', 'post' => 'posted'] as $action => $state) {
                $record = $records->action(new ExecuteRecordActionCommand(
                    $fixture->context,
                    $definition->handle,
                    $record->recordId,
                    $record->version,
                    $action,
                    NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-' . $action),
                ));
                self::assertSame($state, $record->workflowState);
            }
            $view = $records->read(new ReadRecordQuery($fixture->context, $definition->handle, $record->recordId));
            self::assertSame('5005.00', (string) $view->values['total']);
            $history = $records->history(new RecordHistoryQuery(
                $fixture->context,
                $definition->handle,
                $record->recordId,
            ));
            self::assertCount(4, $history->revisions);
            $this->expectException(BusinessRecordImmutable::class);
            $records->update(new UpdateRecordCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                ['title' => 'Forbidden edit'],
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-immutable'),
            ));
        } finally {
            $fixture->dispose();
        }
    }

    /**
     * Preserve relationship targets, owned lines, portal declarations and restricted field disclosure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedRelationshipServicePreservesItsGraphAndDisclosure(): void
    {
        $fixture = $this->fixture('relationship-service');
        try {
            $records = $this->records($fixture);
            $definition = $fixture->definitions['site.default.session5_order'];
            $target = $fixture->definitions['site.default.neutral_target_s5browser'];
            self::assertTrue($definition->portalExposure);
            $person = $records->create(new CreateRecordCommand(
                $fixture->context,
                $target->handle,
                ['label' => 'Service member'],
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-member'),
            ));
            $record = $records->create(new CreateRecordCommand(
                $fixture->context,
                $definition->handle,
                NeutralBusinessFixture::recordValues('Service request'),
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-request'),
            ));
            $record = $records->relate(new RelateRecordsCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                'tags',
                $person->recordId,
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-tag'),
            ));
            $record = $records->relate(new RelateRecordsCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                'lines',
                Uuid::uuid7()->toString(),
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-line'),
                targetValues: ['description' => 'Assistance', 'units' => '1.250'],
            ));
            $view = $records->read(new ReadRecordQuery(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                includes: ['tags', 'lines'],
            ));
            self::assertSame($person->recordId, $view->includes['tags'][0]->recordId);
            self::assertSame('1.250', (string) $view->includes['lines'][0]->values['units']);
            self::assertArrayNotHasKey('secret', $view->values);
            self::assertSame('draft', $view->workflowState);
        } finally {
            $fixture->dispose();
        }
    }

    /**
     * Start an assignment, record parts, labour and measurements, then close its workflow.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedMobileAssignmentCompletesWithThreeOwnedCollections(): void
    {
        $fixture = $this->fixture('mobile-assignment');
        try {
            $records = $this->records($fixture);
            $definition = $fixture->definitions['site.default.browser_job_card'];
            $record = $records->create(new CreateRecordCommand(
                $fixture->context,
                $definition->handle,
                ['title' => 'Survey assignment', 'site_address' => 'Neutral site'],
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-create'),
            ));
            self::assertSame('assigned', $record->workflowState);
            $record = $records->action(new ExecuteRecordActionCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                'start',
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-start'),
            ));
            self::assertSame('in_progress', $record->workflowState);
            foreach (
                [
                'parts' => ['part_number' => 'P1', 'description' => 'Probe', 'quantity' => '2.00'],
                'labour' => ['task' => 'Survey', 'hours' => '1.25'],
                'measurements' => ['metric' => 'Length', 'reading' => '3.50', 'unit' => 'm'],
                ] as $relation => $values
            ) {
                $record = $records->relate(new RelateRecordsCommand(
                    $fixture->context,
                    $definition->handle,
                    $record->recordId,
                    $record->version,
                    $relation,
                    Uuid::uuid7()->toString(),
                    NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-' . $relation),
                    targetValues: $values,
                ));
            }
            $record = $records->action(new ExecuteRecordActionCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                'complete',
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-complete'),
            ));
            $view = $records->read(new ReadRecordQuery(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                includes: ['parts', 'labour', 'measurements'],
            ));
            self::assertSame('completed', $view->workflowState);
            self::assertSame('2.00', (string) $view->includes['parts'][0]->values['quantity']);
            self::assertSame('1.25', (string) $view->includes['labour'][0]->values['hours']);
            self::assertSame('3.50', (string) $view->includes['measurements'][0]->values['reading']);
        } finally {
            $fixture->dispose();
        }
    }

    /**
     * Admit the portal order model and fulfil a versioned order with a recorded payment state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedCatalogueOrderRunsPaymentAndFulfilmentState(): void
    {
        $fixture = $this->fixture('catalogue-order');
        try {
            $records = $this->records($fixture);
            $definition = $fixture->definitions['site.default.browser_shop_order'];
            self::assertTrue($definition->portalExposure);
            $record = $records->create(new CreateRecordCommand(
                $fixture->context,
                $definition->handle,
                ['product' => 'Survey kit', 'quantity' => 2, 'delivery_address' => 'Neutral site'],
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-create'),
            ));
            self::assertSame('placed', $record->workflowState);
            $record = $records->update(new UpdateRecordCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                ['payment_status' => 'paid'],
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-paid'),
            ));
            $record = $records->action(new ExecuteRecordActionCommand(
                $fixture->context,
                $definition->handle,
                $record->recordId,
                $record->version,
                'fulfil',
                NeutralBusinessFixture::idempotencyKey($fixture->keyId . '-fulfil'),
            ));
            $view = $records->read(new ReadRecordQuery($fixture->context, $definition->handle, $record->recordId));
            self::assertSame('fulfilled', $view->workflowState);
            self::assertSame('paid', $view->values['payment_status']);
        } finally {
            $fixture->dispose();
        }
    }

    /**
     * Install a snapshot of the existing browser model under a unique signed owner for this run.
     *
     * @param   string  $family  Checked-in portfolio fragment basename.
     *
     * @return  SignedWorkflowExtensionFixture  Active fixture with fresh authority and schemas.
     *
     * @since   2.0.0
     */
    private function fixture(string $family): SignedWorkflowExtensionFixture
    {
        $path = dirname(__DIR__, 3) . '/examples/extensions/workflow-portfolio/' . $family . '.json';
        $documents = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        foreach ($documents as &$document) {
            $document['id'] = Uuid::uuid7()->toString();
        }
        unset($document);
        $fixture = SignedWorkflowExtensionFixture::install(
            Environment::fromGlobals(),
            'proof/' . substr($family, 0, 5) . '-' . bin2hex(random_bytes(5)),
            $documents,
        );
        foreach ($fixture->definitions as $definition) {
            self::assertSame($fixture->identifier, $definition->owner->identifier);
            self::assertStringStartsWith(str_replace('/', '.', $fixture->identifier) . '.', $definition->handle);
        }
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fixture->sha256);

        return $fixture;
    }

    /**
     * Resolve the actual application service from the signed package's fresh runtime.
     *
     * @param   SignedWorkflowExtensionFixture  $fixture  Active signed owner.
     *
     * @return  BusinessRecordService  Shared business application facade.
     *
     * @since   2.0.0
     */
    private function records(SignedWorkflowExtensionFixture $fixture): BusinessRecordService
    {
        $records = $fixture->container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        return $records;
    }
}
