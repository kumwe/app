<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves the telemetry port refuses concurrency-shaped context before any event is logged.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioTelemetryHostPort::class)]
final class StudioTelemetryHostPortTest extends TestCase
{
    /**
     * An expected revision has no meaning on emit and is refused as invalid context.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnExpectedRevisionIsRefusedAsInvalidContext(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/telemetry.emit',
            (object) ['event' => (object) ['name' => 'studio.telemetry/vector']],
            expectedRevision: 'revision/not-allowed',
        );
        $port = (new StudioTelemetryHostPort(new NullLogger()))->forRequest($request->authority);

        try {
            $port->emit($request->arguments(), $request->context());
            self::fail('The revision-bearing telemetry context was unexpectedly accepted.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
            self::assertSame('studio.host/invalid-context', $refused->error()->diagnostics()[0]->code());
        }
    }
}
