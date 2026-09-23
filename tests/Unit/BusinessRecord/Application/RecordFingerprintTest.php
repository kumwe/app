<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\Conversion\Decimal\ExactDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape the request digest is taken over now that kumwe/record-values owns the value guard.
 *
 * The package guard's `canonical()` applies its depth and node budget to the whole value it is handed, while
 * the record layer has always applied that budget per value: a document of the batch limit of lines is one
 * request, and it fingerprints as a whole. What is proven here is that composition after KUMWE-MIG-2026-029:
 * a thousand-line document digests, the digest ignores the order of string keys at every depth but keeps
 * list order, and a float anywhere in the request is still refused at the App boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(RecordFingerprint::class)]
final class RecordFingerprintTest extends TestCase
{
    /**
     * A thousand-line document, well past the guard's node budget for a single value, digests as one request.
     *
     * Every line carries a decimal, a nested map and a list, so the walk crosses several thousand nodes; the
     * digest is deterministic across two constructions of the same request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineDocumentIsFingerprintedAsOneRequest(): void
    {
        $fingerprints = new RecordFingerprint(str_repeat('k', 32));

        $first = $fingerprints->digest(self::document(1000));
        $second = $fingerprints->digest(self::document(1000));

        self::assertSame(64, strlen($first));
        self::assertSame($first, $second);
    }

    /**
     * String-keyed arrays contribute in sorted key order at every depth while list order carries meaning.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeyOrderNeverReachesTheDigestButListOrderDoes(): void
    {
        $fingerprints = new RecordFingerprint(str_repeat('k', 32));
        $ordered = ['lines' => [['quantity' => 1, 'tags' => ['a', 'b']]], 'title' => 'Order'];
        $shuffled = ['title' => 'Order', 'lines' => [['tags' => ['a', 'b'], 'quantity' => 1]]];
        $reordered = ['title' => 'Order', 'lines' => [['tags' => ['b', 'a'], 'quantity' => 1]]];

        self::assertSame($fingerprints->digest($ordered), $fingerprints->digest($shuffled));
        self::assertNotSame($fingerprints->digest($ordered), $fingerprints->digest($reordered));
    }

    /**
     * A PHP float deep inside a large request is still refused by the guard at the leaf that carries it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFloatDeepInsideALargeRequestIsRefused(): void
    {
        $fingerprints = new RecordFingerprint(str_repeat('k', 32));
        $document = self::document(1000);
        $document['lines'][999]['amount'] = 1.5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business-record values cannot contain PHP floats.');

        $fingerprints->digest($document);
    }

    /**
     * Build a document request with the given number of lines, each holding a decimal, a map and a list.
     *
     * @param   int  $lines  Number of lines to generate.
     *
     * @return  array<string, mixed>  The request as the record service fingerprints it.
     *
     * @since   2.0.0
     */
    private static function document(int $lines): array
    {
        $document = ['definition' => 'unit.document', 'title' => 'Fingerprint', 'lines' => []];
        for ($index = 0; $index < $lines; ++$index) {
            $document['lines'][] = [
                'line_number' => $index + 1,
                'amount' => ExactDecimal::fromString('12.50', 18, 2),
                'dimensions' => ['cost_centre' => 'cc-' . ($index % 7), 'project' => 'p-' . ($index % 3)],
                'tags' => ['unit', 'line-' . $index],
            ];
        }

        return $document;
    }
}
