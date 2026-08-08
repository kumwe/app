<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Contract rules the published OpenAPI document must satisfy on its own.
 *
 * Whether a documented path is actually routed, and which capability it demands, is proved
 * against the live router in ManagementDeliveryTest: that needs a booted container, which
 * this suite deliberately runs without. Here we only check the document is internally
 * sound, because a contract that references missing components fails consumers at
 * generation time rather than at request time.
 */
#[CoversNothing]
final class DeliveryParityTest extends TestCase
{
    private const PUBLIC_PATH_PREFIXES = ['/health', '/api/v1/public', '/.well-known'];

    private const HTTP_METHODS = ['get', 'put', 'post', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @throws JsonException */
    public function testEveryManagementOperationDeclaresBearerAndExplicitSiteBinding(): void
    {
        $document = $this->document();
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertNotSame([], $paths);

        $checked = 0;
        foreach ($paths as $path => $operations) {
            self::assertIsString($path);
            self::assertIsArray($operations);
            if ($this->isPublic($path)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                // A path item may also carry shared "parameters" and "summary" members.
                if (!in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }
                self::assertIsArray($operation);
                self::assertArrayHasKey(
                    'operationId',
                    $operation,
                    sprintf('%s %s has no operationId.', strtoupper((string) $method), $path),
                );
                self::assertSame(
                    [['bearerAuth' => [], 'siteContext' => []]],
                    $operation['security'] ?? null,
                    sprintf(
                        '%s %s does not require both bearer and explicit site binding.',
                        strtoupper((string) $method),
                        $path,
                    ),
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(60, $checked, 'The management contract lost operations unexpectedly.');
    }

    /** @throws JsonException */
    public function testOperationIdentifiersAreUnique(): void
    {
        $document = $this->document();
        $paths = $document['paths'] ?? [];
        self::assertIsArray($paths);

        $seen = [];
        foreach ($paths as $path => $operations) {
            self::assertIsArray($operations);
            foreach ($operations as $method => $operation) {
                if (!in_array($method, self::HTTP_METHODS, true) || !is_array($operation)) {
                    continue;
                }
                $id = $operation['operationId'] ?? null;
                if (!is_string($id)) {
                    continue;
                }
                self::assertArrayNotHasKey(
                    $id,
                    $seen,
                    sprintf('operationId "%s" is reused by %s %s.', $id, strtoupper((string) $method), $path),
                );
                $seen[$id] = true;
            }
        }
    }

    /** @throws JsonException */
    public function testEveryComponentReferenceResolves(): void
    {
        $document = $this->document();
        $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        preg_match_all('~"\$ref"\s*:\s*"(#/[^"]+)"~', $encoded, $matches);
        self::assertNotSame([], $matches[1], 'The contract declares no component references.');

        foreach (array_unique($matches[1]) as $reference) {
            $node = $document;
            foreach (explode('/', ltrim($reference, '#/')) as $segment) {
                self::assertIsArray($node, sprintf('Reference %s does not resolve.', $reference));
                self::assertArrayHasKey($segment, $node, sprintf('Reference %s does not resolve.', $reference));
                $node = $node[$segment];
            }
        }
    }

    private function isPublic(string $path): bool
    {
        foreach (self::PUBLIC_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return $path === '/api/v1';
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function document(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/api/openapi/kumwe-v1.json');
        self::assertIsString($contents, 'The published OpenAPI contract could not be read.');
        $document = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
