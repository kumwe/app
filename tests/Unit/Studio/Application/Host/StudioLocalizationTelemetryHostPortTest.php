<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Application\TranslationScope;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\App\Studio\Application\Host\StudioHostOperationRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort;
use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Replays the exact AP-7 localization and telemetry host vectors.
 *
 * Covered IDs: `vector.host-vector.localization.messages.unknown-locale`,
 * `vector.host-vector.telemetry.emit.accepted`, and
 * `vector.host-vector.telemetry.emit.non-primitive`.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioLocalizationHostPort::class)]
#[CoversClass(StudioTelemetryHostPort::class)]
final class StudioLocalizationTelemetryHostPortTest extends TestCase
{
    /**
     * The exact alpha.9 160-key corpus resolves through an organization/site-aware chain.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testLocalizationReturnsExactCorpusAndUnknownLocaleIsNotFound(): void
    {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(LocaleTag::fromString('en-GB'), new TranslationScope('default'));
        $port = new StudioLocalizationHostPort(
            new CompiledMessageCatalogueRepository(dirname(__DIR__, 5) . '/resources/localization/compiled'),
            new ArrayMessageOverrideRepository(site: [
                'default' => ['en-GB' => ['core.studio.shell.undo' => 'Undo composition']],
            ]),
            $active,
            $supported,
        );

        $result = $port->dispatch('messages', self::request(
            'studio.operation/localization.messages',
            (object) ['locale' => 'en-GB', 'namespaces' => ['studio.shell']],
        ), self::snapshot());

        self::assertCount(160, get_object_vars($result->value));
        self::assertSame('Undo composition', $result->value->{'studio.shell/undo'});
        try {
            $port->dispatch('messages', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'zz', 'namespaces' => ['studio.shell']],
            ), self::snapshot());
            self::fail('The unknown locale vector unexpectedly resolved.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('not-found', $refused->category);
        }
    }

    /**
     * Primitive attributes are accepted without values becoming observability labels or fields.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testTelemetryAcceptsPrimitiveVectorAndRejectsNestedVector(): void
    {
        $logger = new StudioTelemetryTestLogger();
        $port = new StudioTelemetryHostPort($logger);
        $result = $port->dispatch('emit', self::request(
            'studio.operation/telemetry.emit',
            (object) ['event' => (object) [
                'attributes' => (object) ['surface' => 'canvas'],
                'name' => 'studio.telemetry/vector',
            ]],
        ), self::snapshot());

        self::assertNull($result->value);
        self::assertSame(['surface'], $logger->records[0]['context']['attribute_names']);
        self::assertArrayNotHasKey('attributes', $logger->records[0]['context']);
        self::assertNotContains('canvas', $logger->records[0]['context']);
        try {
            $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'attributes' => (object) ['surface' => (object) ['nested' => true]],
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot());
            self::fail('The nested telemetry vector unexpectedly succeeded.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('invalid-request', $refused->category);
        }
    }

    /**
     * Build one valid exact host request for a vector operation.
     *
     * @param   string  $operation  Canonical operation capability.
     * @param   object  $arguments  Exact vector arguments.
     *
     * @return  StudioHostRequest  Valid host request envelope.
     *
     * @since  2.0.0
     */
    private static function request(string $operation, object $arguments): StudioHostRequest
    {
        return new StudioHostRequest(
            $operation,
            '0.1.0-draft.2',
            'requests/vector',
            'contexts/vector',
            'session-r1',
            $arguments,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Build one live trusted session snapshot for host-port vectors.
     *
     * @return  StudioHostSessionSnapshot  Deterministic trusted session snapshot.
     *
     * @since  2.0.0
     */
    private static function snapshot(): StudioHostSessionSnapshot
    {
        return new StudioHostSessionSnapshot(new StudioHostSession(
            'contexts/vector',
            '018f22e2-7c8b-7ab0-8f3a-88e8026be710',
            'default',
            null,
            null,
            AuthenticatedSurface::Administrator->value,
            hash('sha256', 'test-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprints/vector',
            'session-r1',
        ), [], 'session-r1', true, false, false);
    }
}

/**
 * Captures telemetry records without a production transport.
 *
 * @since  2.0.0
 */
final class StudioTelemetryTestLogger extends AbstractLogger
{
    /**
     * Captured structured log records.
     *
     * @var    list<array{message: string, context: array<string, mixed>}>
     * @since  2.0.0
     */
    public array $records = [];

    /**
     * Capture one structured log record for assertion.
     *
     * @param   mixed                 $level    PSR log level.
     * @param   string|\Stringable    $message  Structured log message.
     * @param   array<string, mixed>  $context  Safe structured context.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        unset($level);
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
