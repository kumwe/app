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
use stdClass;

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
     * The exact corpus resolves through the effective chain and every malformed request is refused.
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
        self::assertPortRefused(
            static fn () => $port->dispatch('unknown', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'en-GB', 'namespaces' => ['studio.shell']],
            ), self::snapshot()),
            'incompatible',
            'studio.host/operation-unavailable',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::hostRequest(
                'studio.operation/localization.messages',
                (object) ['locale' => 'en-GB', 'namespaces' => ['studio.shell']],
                expectedRevision: 'revision/not-allowed',
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-context',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::hostRequest(
                'studio.operation/localization.messages',
                null,
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'en-GB', 'namespaces' => []],
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'en-GB', 'namespaces' => ['studio/shell']],
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'not_locale!', 'namespaces' => ['studio.shell']],
            ), self::snapshot()),
            'not-found',
            'studio.localization/locale-not-found',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('messages', self::request(
                'studio.operation/localization.messages',
                (object) ['locale' => 'zz', 'namespaces' => ['studio.shell']],
            ), self::snapshot()),
            'not-found',
            'studio.localization/locale-not-found',
        );
    }

    /**
     * Primitive attributes are accepted while every unbounded or malformed event is refused.
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
        self::assertPortRefused(
            static fn () => $port->dispatch('unknown', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'incompatible',
            'studio.host/operation-unavailable',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::hostRequest(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) ['name' => 'studio.telemetry/vector']],
                idempotencyKey: 'idempotency/not-allowed',
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-context',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::hostRequest(
                'studio.operation/telemetry.emit',
                null,
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => 'not-an-object'],
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'extra' => true,
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) ['name' => 'invalid-event-name']],
            ), self::snapshot()),
            'invalid-request',
            'studio.telemetry/invalid-event',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'attributes' => [],
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'attributes' => (object) ['surface' => (object) ['nested' => true]],
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'attributes' => (object) ['surface' => str_repeat('x', 201)],
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        $largeAttributes = new stdClass();
        for ($index = 0; $index < 32; $index++) {
            $largeAttributes->{sprintf('attribute_%02d', $index)} = str_repeat('x', 200);
        }
        self::assertPortRefused(
            static fn () => $port->dispatch('emit', self::request(
                'studio.operation/telemetry.emit',
                (object) ['event' => (object) [
                    'attributes' => $largeAttributes,
                    'name' => 'studio.telemetry/vector',
                ]],
            ), self::snapshot()),
            'limit-exceeded',
            'studio.telemetry/event-too-large',
        );
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
        return self::hostRequest($operation, $arguments);
    }

    /**
     * Build one host request whose malformed runtime values are deliberately under test.
     *
     * @param   string       $operation         Canonical operation capability.
     * @param   mixed        $arguments         Candidate vector arguments.
     * @param   string|null  $expectedRevision  Optional forbidden read revision.
     * @param   string|null  $idempotencyKey    Optional forbidden read idempotency key.
     *
     * @return  StudioHostRequest  Host request envelope carrying the supplied values.
     *
     * @since  2.0.0
     */
    private static function hostRequest(
        string $operation,
        mixed $arguments,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): StudioHostRequest {
        return new StudioHostRequest(
            $operation,
            '0.1.0-draft.2',
            'requests/vector',
            'contexts/vector',
            'session-r1',
            $arguments,
            $expectedRevision,
            $idempotencyKey,
            null,
            null,
        );
    }

    /**
     * Assert one host-port refusal without allowing the next rejection vector to be skipped.
     *
     * @param   callable(): mixed  $operation  Host operation expected to fail closed.
     * @param   string             $category   Expected canonical refusal category.
     * @param   string             $code       Expected canonical diagnostic code.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertPortRefused(callable $operation, string $category, string $code): void
    {
        try {
            $operation();
            self::fail('The malformed host-port request unexpectedly succeeded.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame($category, $refused->category);
            self::assertSame($code, $refused->diagnosticCode);
        }
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
