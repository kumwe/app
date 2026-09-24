<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
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
 * Proves a refused record rule is stated on the re-rendered form instead of vanishing from it.
 *
 * A document header declares the record rule `total_agrees_with_lines`. When an operator edits the total so
 * it no longer equals the sum of the lines, the record service refuses the write with a violation reported
 * under the rule's handle, which is not a field on the form. The generated form must still say why: the
 * rule's declared message is carried in the error summary, the operator's typed values are kept, and a
 * corrected submission succeeds.
 *
 * @since  2.0.0
 */
#[CoversClass(GeneratedBusinessBrowserController::class)]
final class GeneratedRecordRuleRefusalIntegrationTest extends TestCase
{
    /**
     * A record-rule refusal is summarized with the rule's message and the typed values survive to a retry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARecordRuleRefusalIsStatedOnTheFormAndTheCorrectedSaveSucceeds(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $browser = $container->get(GeneratedBusinessBrowserController::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(GeneratedBusinessBrowserController::class, $browser);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
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
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Rule document ' . $suffix, 'total' => '3.00'],
            [
                new DocumentLineInput(['code' => 'rule-' . $suffix . '-1', 'description' => 'One', 'amount' => '1.00']),
                new DocumentLineInput(['code' => 'rule-' . $suffix . '-2', 'description' => 'Two', 'amount' => '2.00']),
            ],
            NeutralBusinessFixture::idempotencyKey('rule-document-' . $suffix),
            recordId: $documentId,
        ));

        $refused = $this->save($browser, $context, $header->handle, $documentId, 'Reviewed ' . $suffix, '3.01');

        self::assertSame(422, $refused->status);
        self::assertSame('business-form', $refused->template);
        self::assertSame(
            ['The document total must equal the sum of its lines.'],
            $refused->data['record_errors'] ?? null,
            'The rule the write broke is stated, not only "review the marked fields".',
        );
        $fields = $refused->data['fields'] ?? null;
        self::assertIsArray($fields);
        $values = [];
        foreach ($fields as $field) {
            self::assertIsArray($field);
            self::assertIsString($field['handle'] ?? null);
            $values[$field['handle']] = $field['input_value'] ?? null;
        }
        self::assertSame('Reviewed ' . $suffix, $values['title'] ?? null, 'The typed title is kept for the retry.');

        $saved = $this->save($browser, $context, $header->handle, $documentId, 'Reviewed ' . $suffix, '3.00');

        self::assertSame(303, $saved->status, 'The corrected total saves.');
        self::assertNotNull($saved->redirect);
    }

    /**
     * Submit a header edit through the administrator generated form.
     *
     * @param   GeneratedBusinessBrowserController  $browser   Controller under test.
     * @param   ExecutionContext                    $context   Administrator.
     * @param   string                              $handle    Header definition handle.
     * @param   string                              $record    Document identifier.
     * @param   string                              $title     Submitted title.
     * @param   string                              $total     Submitted total.
     *
     * @return  BusinessBrowserResult  The controller's answer.
     *
     * @since   2.0.0
     */
    private function save(
        GeneratedBusinessBrowserController $browser,
        ExecutionContext $context,
        string $handle,
        string $record,
        string $title,
        string $total,
    ): BusinessBrowserResult {
        return $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $handle,
            $record,
            [],
            [
                'operation' => 'update',
                'operation_id' => 'rule-update-' . Uuid::uuid7()->toString(),
                'expected_version' => '1',
                'values' => ['title' => $title, 'total' => $total],
            ],
        );
    }
}
