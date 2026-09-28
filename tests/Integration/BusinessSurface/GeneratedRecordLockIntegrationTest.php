<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessBrowserResult;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the generated browser surfaces answer immutable and closed-period refusals as read-only pages.
 *
 * A definition declaring an immutable `approved` state and a `posted_on` posting date is installed on
 * the real engine. The catalogue metadata must expose both declarations; a record in the immutable
 * state and a record dated inside a closed posting period must render read-only affordances carrying
 * the refusal's own wording; and a submission that the record service refuses for either reason must
 * come back as a 409 page instead of reaching the global error boundary. A new record dated inside the
 * closed period keeps its values on the form with the posting-date field marked.
 *
 * @since  2.0.0
 */
#[CoversClass(GeneratedBusinessBrowserController::class)]
#[CoversClass(BusinessSurfaceCatalog::class)]
final class GeneratedRecordLockIntegrationTest extends TestCase
{
    /**
     * Base path the administrator generated surface is mounted under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string BASE = '/administrator/business';

    /**
     * Immutable and closed-period records render read-only, and their refused writes answer 409 pages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testImmutableAndClosedPeriodRecordsRenderReadOnlyAndRefusedWritesStayOnThePage(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $periods = $container->get(PostingPeriodService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $handle = $this->install($container, $context, $suffix);
        $base = (new DateTimeImmutable('4100-01-01T00:00:00Z'))
            ->modify('+' . ((int) hexdec(substr($suffix, 0, 8)) % 250_000) . ' days');
        $period = 'lock-' . $suffix;

        $open = $this->create($records, $context, $handle, 'Open record', $base->modify('+40 days'), 'open');
        $immutable = $this->create($records, $context, $handle, 'Approved record', $base->modify('+40 days'), 'imm');
        $records->action(new ExecuteRecordActionCommand(
            $context,
            $handle,
            $immutable,
            1,
            'approve',
            NeutralBusinessFixture::idempotencyKey('lock-approve-' . $suffix),
        ));
        $dated = $this->create($records, $context, $handle, 'Dated record', $base->modify('+2 days'), 'dated');
        $periods->close($context, $period, $base, $base->modify('+10 days'));

        try {
            $detail = $this->get($browser, $context, $handle, $open);
            self::assertSame('business-detail', $detail->template);
            self::assertNull($detail->data['record_lock'], 'An open record keeps its edit affordances.');
            $definition = $detail->data['definition'] ?? null;
            self::assertIsArray($definition);
            $workflow = $definition['workflow'] ?? null;
            self::assertIsArray($workflow);
            self::assertSame(['approved'], $workflow['immutable_states']);
            self::assertSame('posted_on', $definition['posting_date_field']);

            $immutableWording = (new BusinessRecordImmutable('approved'))->getMessage();
            foreach ([[], ['edit' => '1']] as $query) {
                $page = $this->get($browser, $context, $handle, $immutable, $query);
                self::assertSame(
                    ['code' => 'business_record.immutable', 'message' => $immutableWording],
                    $page->data['record_lock'],
                );
            }
            $refused = $this->update($browser, $context, $handle, $immutable, 2, 'Rewritten after approval');
            self::assertSame(409, $refused->status);
            self::assertSame('business-detail', $refused->template);
            self::assertSame(
                ['code' => 'business_record.immutable', 'message' => $immutableWording],
                $refused->data['record_lock'],
            );

            $periodWording = (new BusinessRecordPostingPeriodClosed($period, $base))->getMessage();
            $page = $this->get($browser, $context, $handle, $dated);
            self::assertSame(
                ['code' => 'business_record.posting_period_closed', 'message' => $periodWording],
                $page->data['record_lock'],
            );
            $refused = $this->update($browser, $context, $handle, $dated, 1, 'Edited after close');
            self::assertSame(409, $refused->status);
            self::assertSame('business-detail', $refused->template);
            self::assertSame(
                ['code' => 'business_record.posting_period_closed', 'message' => $periodWording],
                $refused->data['record_lock'],
            );

            $moved = $this->update(
                $browser,
                $context,
                $handle,
                $open,
                1,
                'Moved into the closed period',
                $base->modify('+3 days')->format('Y-m-d'),
            );
            self::assertSame(409, $moved->status, 'An open record dated into a closed period is refused.');
            self::assertSame('business-form', $moved->template, 'The open record keeps its form.');
            self::assertSame($periodWording, $moved->data['error_summary']);
            self::assertNull($moved->data['record_lock']);
            self::assertSame([$periodWording], $this->fieldErrors($moved, 'posted_on'));

            $created = $browser->dispatch(
                $context,
                BusinessSurface::Administrator,
                self::BASE,
                'POST',
                $handle,
                null,
                [],
                [
                    'operation' => 'create',
                    'operation_id' => 'lock-create-' . $suffix,
                    'values' => [
                        'label' => 'Created into a closed period ' . $suffix,
                        'posted_on' => $base->modify('+4 days')->format('Y-m-d'),
                    ],
                ],
            );
            self::assertSame(409, $created->status);
            self::assertSame('business-form', $created->template);
            self::assertSame($periodWording, $created->data['error_summary']);
            self::assertSame([$periodWording], $this->fieldErrors($created, 'posted_on'));
        } finally {
            $periods->reopen($context, $period);
        }

        $reopened = $this->get($browser, $context, $handle, $dated);
        self::assertNull($reopened->data['record_lock'], 'Reopening the period restores the edit affordances.');
    }

    /**
     * Install a flat definition with an immutable `approved` state and a `posted_on` posting date.
     *
     * @param   \Kumwe\App\Kernel\Container  $container  Real integration container.
     * @param   ExecutionContext             $context    Administrator the installation runs as.
     * @param   string                       $suffix     Per-run fixture suffix.
     *
     * @return  string  The installed definition handle.
     *
     * @since   2.0.0
     */
    private function install(\Kumwe\App\Kernel\Container $container, ExecutionContext $context, string $suffix): string
    {
        $document = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        $document['handle'] = 'site.default.record_lock_' . $suffix;
        $fields = $document['fields'];
        self::assertIsArray($fields);
        $fields[] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'required' => false,
            'nullable' => true,
            'filterable' => true,
            'configuration' => ['posting_date' => true],
        ];
        $document['fields'] = $fields;
        $document['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'immutable_states' => ['approved'],
            'transitions' => [[
                'handle' => 'approve',
                'from' => 'draft',
                'to' => 'approved',
                'capability' => 'business.record.action',
            ]],
        ];
        $document['actions'] = [[
            'handle' => 'approve',
            'label' => 'Approve',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'transition' => 'approve',
        ]];

        return NeutralBusinessFixture::install($container, $context, $document)->handle;
    }

    /**
     * Create one record through the record service.
     *
     * @param   BusinessRecordService  $records   Record facade.
     * @param   ExecutionContext       $context   Administrator.
     * @param   string                 $handle    Definition handle.
     * @param   string                 $label     Unique label stem.
     * @param   DateTimeImmutable      $postedOn  Posting date.
     * @param   string                 $key       Idempotency stem.
     *
     * @return  string  The new record identifier.
     *
     * @since   2.0.0
     */
    private function create(
        BusinessRecordService $records,
        ExecutionContext $context,
        string $handle,
        string $label,
        DateTimeImmutable $postedOn,
        string $key,
    ): string {
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $handle,
            ['label' => $label . ' ' . $recordId, 'posted_on' => $postedOn->format('Y-m-d')],
            NeutralBusinessFixture::idempotencyKey('lock-create-' . $key . '-' . $recordId),
            recordId: $recordId,
        ));

        return $recordId;
    }

    /**
     * Render one record page through the controller.
     *
     * @param   GeneratedBusinessBrowserController  $browser  Controller under test.
     * @param   ExecutionContext                    $context  Administrator.
     * @param   string                              $handle   Definition handle.
     * @param   string                              $record   Record identifier.
     * @param   array<string, string>               $query    Page query.
     *
     * @return  BusinessBrowserResult  The rendered page model.
     *
     * @since   2.0.0
     */
    private function get(
        GeneratedBusinessBrowserController $browser,
        ExecutionContext $context,
        string $handle,
        string $record,
        array $query = [],
    ): BusinessBrowserResult {
        return $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            self::BASE,
            'GET',
            $handle,
            $record,
            $query,
            [],
        );
    }

    /**
     * Submit an update through the controller.
     *
     * @param   GeneratedBusinessBrowserController  $browser   Controller under test.
     * @param   ExecutionContext                    $context   Administrator.
     * @param   string                              $handle    Definition handle.
     * @param   string                              $record    Record identifier.
     * @param   int                                 $version   Expected version.
     * @param   string                              $label     New label.
     * @param   ?string                             $postedOn  New posting date, or null to leave it.
     *
     * @return  BusinessBrowserResult  The controller's answer.
     *
     * @since   2.0.0
     */
    private function update(
        GeneratedBusinessBrowserController $browser,
        ExecutionContext $context,
        string $handle,
        string $record,
        int $version,
        string $label,
        ?string $postedOn = null,
    ): BusinessBrowserResult {
        $values = ['label' => $label . ' ' . $record];
        if ($postedOn !== null) {
            $values['posted_on'] = $postedOn;
        }

        return $browser->dispatch($context, BusinessSurface::Administrator, self::BASE, 'POST', $handle, $record, [], [
            'operation' => 'update',
            'operation_id' => 'lock-update-' . Uuid::uuid7()->toString(),
            'expected_version' => (string) $version,
            'values' => $values,
        ]);
    }

    /**
     * Read the errors a re-rendered form attaches to one field.
     *
     * @param   BusinessBrowserResult  $result  Form page model.
     * @param   string                 $handle  Field handle.
     *
     * @return  mixed  The field's error list.
     *
     * @since   2.0.0
     */
    private function fieldErrors(BusinessBrowserResult $result, string $handle): mixed
    {
        $fields = $result->data['fields'] ?? null;
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            if (is_array($field) && ($field['handle'] ?? null) === $handle) {
                return $field['errors'] ?? null;
            }
        }
        self::fail('The form does not render the ' . $handle . ' field.');
    }
}
