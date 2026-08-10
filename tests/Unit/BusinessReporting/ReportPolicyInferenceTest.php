<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\CMS\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\CMS\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\CMS\BusinessReporting\Application\ReportService;
use Kumwe\CMS\BusinessReporting\Application\ReportUnavailable;
use Kumwe\CMS\BusinessReporting\Domain\ReportAggregateDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportAggregateFunction;
use Kumwe\CMS\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportGroupDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportService::class)]
final class ReportPolicyInferenceTest extends TestCase
{
    public function testMissingPolicyControlledGroupValueFailsInsteadOfChangingTotals(): void
    {
        $report = new ReportDefinition(
            'acme.revenue',
            1,
            'Revenue',
            'acme.invoice',
            'acme.reports.read',
            [],
            [],
            [
                new ReportColumnDefinition('region', 'Region', 'region', ReportValueType::String),
                new ReportColumnDefinition('amount', 'Amount', 'amount', ReportValueType::Decimal),
            ],
            [new ReportGroupDefinition('region')],
            [new ReportAggregateDefinition('total', ReportAggregateFunction::Sum, 'amount')],
        );
        $reader = new class implements BusinessRecordReportReader {
            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
                return new RecordBrowseResult([new BusinessRecordView(
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb601',
                    1,
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb602',
                    'INV-1',
                    1,
                    'default',
                    null,
                    null,
                    ['amount' => '10.00'],
                    AuthorizationContext::SUBJECT,
                    $now,
                    AuthorizationContext::SUBJECT,
                    $now,
                    null,
                    null,
                    null,
                    null,
                )]);
            }
        };
        $service = new ReportService(
            new ReportDefinitionRegistry([$report]),
            $reader,
            AuthorizationContext::gateway(),
        );
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.report']);

        $this->expectException(ReportUnavailable::class);
        $service->execute(new ReportExecutionRequest($context, 'acme.revenue'));
    }
}
