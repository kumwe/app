<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdministratorRequest::class)]
final class AdministratorRequestTest extends TestCase
{
    public function testNormalizesAStringListForGraphicalMultiSelectControls(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://kumwe.test/administrator')
            ->withParsedBody([
                'token_capabilities' => ['content.read', 'content.update'],
                'ignored_nested_object' => ['unexpected' => 'value'],
            ]);

        self::assertSame([
            'token_capabilities' => 'content.read,content.update',
        ], AdministratorRequest::form($request));
    }
}
