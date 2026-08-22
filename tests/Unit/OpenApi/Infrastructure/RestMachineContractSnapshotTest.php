<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Infrastructure;

use JsonException;
use Kumwe\App\OpenApi\Infrastructure\RestMachineContractSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the retained REST generation against its independent machine documents.
 *
 * @since  2.0.0
 */
#[CoversClass(RestMachineContractSnapshot::class)]
final class RestMachineContractSnapshotTest extends TestCase
{
    /**
     * Reproduce the accepted semantic fixture from the published OpenAPI and problem registry.
     *
     * @return  void
     *
     * @throws  JsonException  When a checked-in contract document is malformed.
     *
     * @since   2.0.0
     */
    public function testCheckedInSnapshotMatchesItsIndependentDocuments(): void
    {
        $root = dirname(__DIR__, 4);
        $openApi = $this->object($root . '/api/openapi/kumwe-v1.json');
        $problems = $this->object($root . '/api/problem-details/kumwe-v1.json');
        $fixture = $this->object($root . '/api/openapi/compatibility/v1.json');

        self::assertSame($fixture, RestMachineContractSnapshot::create($openApi, $problems));
    }

    /**
     * Decode one checked-in JSON object without weakening its object-key contract.
     *
     * @param   string  $path  Absolute contract path.
     *
     * @return  array<string, mixed>  Decoded machine document.
     *
     * @throws  JsonException  When the document is malformed.
     *
     * @since   2.0.0
     */
    private function object(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $document = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertFalse(array_is_list($document));

        /** @var array<string, mixed> $document */
        return $document;
    }
}
