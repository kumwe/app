<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Automation;

use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use InvalidArgumentException;
use Kumwe\CMS\Administrator\Automation\AutomationJobField;
use Kumwe\CMS\Administrator\Automation\AutomationJobFormRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomationJobFormRegistry::class)]
#[UsesClass(AutomationJobField::class)]
final class AutomationJobFormRegistryTest extends TestCase
{
    public function testMapsTypedGraphicalFieldsToAJobPayload(): void
    {
        $registry = AutomationJobFormRegistry::core(InterfaceTranslation::translator());
        $payload = $registry->payload('content.workflow.transition', [
            'payload__id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            'payload__version' => '4',
            'payload__status' => 'published',
        ]);

        self::assertSame([
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            'version' => 4,
            'status' => 'published',
        ], $payload);
        self::assertSame(
            'Purge expired administrator sessions',
            $registry->definitions(['system.sessions.purge'])[0]['label'],
        );
    }

    public function testRejectsAnOutOfRangeGraphicalValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside its limits');

        AutomationJobFormRegistry::core(InterfaceTranslation::translator())->payload('system.idempotency.purge', [
            'payload__batch_size' => '1000',
            'payload__maximum_batches' => '101',
        ]);
    }
}
