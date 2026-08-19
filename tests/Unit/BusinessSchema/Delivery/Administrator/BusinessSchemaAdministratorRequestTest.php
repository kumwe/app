<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Delivery\Administrator;

use Kumwe\App\BusinessSchema\Delivery\Administrator\BusinessSchemaAdministratorRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessSchemaAdministratorRequest::class)]
final class BusinessSchemaAdministratorRequestTest extends TestCase
{
    public function testAcceptsOnlyBoundedContextualTabs(): void
    {
        self::assertSame('operations', BusinessSchemaAdministratorRequest::activeTab('operations'));
        self::assertSame('summary', BusinessSchemaAdministratorRequest::activeTab('unknown'));
        self::assertSame('approval', BusinessSchemaAdministratorRequest::activeTab(null, 'approval'));
        self::assertSame('summary', BusinessSchemaAdministratorRequest::activeTab(null, 'unknown'));
    }

    public function testRedirectPreservesPlanEvidenceNoticeAndKnownTab(): void
    {
        $response = BusinessSchemaAdministratorRequest::redirect(
            'plan-with-a-long-stable-identifier',
            'evidence-recorded',
            'evidence-with-a-long-stable-identifier',
            'recovery',
        );

        $query = [];
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame([
            'plan' => 'plan-with-a-long-stable-identifier',
            'notice' => 'evidence-recorded',
            'tab' => 'recovery',
            'evidence' => 'evidence-with-a-long-stable-identifier',
        ], $query);
        self::assertSame(303, $response->getStatusCode());
    }

    public function testRedirectRejectsAnUnboundedTab(): void
    {
        $response = BusinessSchemaAdministratorRequest::redirect('plan-id', 'executed', null, '<script>');

        self::assertStringContainsString('tab=summary', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('script', $response->getHeaderLine('Location'));
    }
}
