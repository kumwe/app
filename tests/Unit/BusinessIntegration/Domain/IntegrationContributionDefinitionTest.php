<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventSchemaDefinition::class)]
#[CoversClass(ScheduleContributionDefinition::class)]
/**
 * Proves the contributed integration definitions hold their declared payload and time bounds.
 *
 * @since  2.0.0
 */
final class IntegrationContributionDefinitionTest extends TestCase
{
    /**
     * Prove an event schema outside its envelope payload ceiling never becomes a contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEventSchemaPayloadCeilingOutsideTheEnvelopeIsRefused(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['record_id'],
            'properties' => ['record_id' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $valid = new EventSchemaDefinition('acme.sample.changed', 1, EventSensitivity::INTERNAL, $schema);
        self::assertSame('acme.sample.changed', $valid->eventType());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload ceiling is invalid');

        new EventSchemaDefinition('acme.sample.changed', 1, EventSensitivity::INTERNAL, $schema, 1);
    }

    /**
     * Prove a contributed schedule validates its complete declaration and refuses a foreign timezone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAScheduleOutsideTheCanonicalTimezoneCatalogueIsRefused(): void
    {
        $valid = new ScheduleContributionDefinition(
            'acme.sample.nightly',
            'acme.sample.rebuild',
            '0 2 * * *',
            'UTC',
            ['scope' => 'all'],
            'acme.sample',
            'default',
        );
        self::assertSame('acme.sample.nightly', $valid->identifier());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timezone is invalid');

        new ScheduleContributionDefinition(
            'acme.sample.nightly',
            'acme.sample.rebuild',
            '0 2 * * *',
            'Neverland/Nowhere',
            ['scope' => 'all'],
        );
    }
}
