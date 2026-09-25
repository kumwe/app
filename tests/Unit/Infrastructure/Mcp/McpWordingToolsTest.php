<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryWording;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Proves the MCP wording reads answer the REST shapes and the wording tools refuse before any write is fenced.
 *
 * Saves and withdrawals run through the database-backed mutation guard and are proven end to end by the
 * wording machine-equivalence integration test; here the reads run against the real `MessageOverrideService`
 * over an in-memory store, and every refusal raised before the guard is pinned to its exception.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
final class McpWordingToolsTest extends TestCase
{
    /**
     * Listing and catalogue search answer the REST documents.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadsAnswerTheRestShapes(): void
    {
        $wording = new InMemoryWording();
        $audit = new RecordingAuditRecorder();
        $wording->service($audit)->override(
            AuthorizationContext::human(['localization.overrides.manage']),
            MessageCatalogueLayer::Site,
            'en-GB',
            InMemoryWording::IDENTIFIER,
            'Customer',
        );
        $handlers = $this->handlers(['localization.overrides.manage'], $wording);

        $listed = $handlers->listWordingOverrides('site', 'en-GB');
        $found = $handlers->searchWordingCatalogue('en-GB', 'client', 10);

        self::assertSame('site', $listed['layer']);
        self::assertSame('en-GB', $listed['locale']);
        self::assertSame([InMemoryWording::IDENTIFIER], array_column($listed['items'], 'identifier'));
        self::assertSame('Customer', $listed['items'][0]['pattern']);
        self::assertSame('en-GB', $found['locale']);
        self::assertSame([InMemoryWording::IDENTIFIER], array_column($found['items'], 'identifier'));
    }

    /**
     * Unadministered layers, missing grants and an uncomposed service are refused before the mutation guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrecedeTheMutationGuard(): void
    {
        $manage = ['localization.overrides.manage'];
        $refusals = [
            [InvalidArgumentException::class, fn () => $this->handlers($manage)->listWordingOverrides('core')],
            [InvalidArgumentException::class, fn () => $this->handlers($manage)->saveWordingOverride(
                'wording-save-000000001',
                'core',
                'en-GB',
                InMemoryWording::IDENTIFIER,
                'A',
            )],
            [InvalidArgumentException::class, fn () => $this->handlers($manage)->withdrawWordingOverride(
                'wording-withdraw-00001',
                'extension',
                'en-GB',
                InMemoryWording::IDENTIFIER,
            )],
            [InsufficientCapability::class, fn () => $this->handlers(['content.read'])->saveWordingOverride(
                'wording-save-000000002',
                'site',
                'en-GB',
                InMemoryWording::IDENTIFIER,
                'A',
            )],
            [
                InsufficientCapability::class,
                fn () => $this->handlers(['content.read'])->searchWordingCatalogue('en-GB'),
            ],
            [InvalidArgumentException::class, fn () => $this->handlers($manage, null)->listWordingOverrides()],
        ];

        foreach ($refusals as $index => [$expected, $call]) {
            try {
                $call();
                self::fail(sprintf('Refusal %d was not raised.', $index));
            } catch (Throwable $refusal) {
                self::assertInstanceOf($expected, $refusal, (string) $index);
            }
        }
    }

    /**
     * Build handlers bound to a bearer context with the given capabilities.
     *
     * @param   list<string>          $capabilities  Capabilities the MCP credential holds.
     * @param   InMemoryWording|null  $wording       Wording store, or null for a server without wording.
     *
     * @return  KumweMcpHandlers  Bound handlers.
     *
     * @since   2.0.0
     */
    private function handlers(array $capabilities, ?InMemoryWording $wording = new InMemoryWording()): KumweMcpHandlers
    {
        return McpHandlersFixture::create(
            new McpCapabilityCatalog(),
            wording: $wording?->service(new RecordingAuditRecorder()),
        )->forContext(AuthorizationContext::human($capabilities));
    }
}
