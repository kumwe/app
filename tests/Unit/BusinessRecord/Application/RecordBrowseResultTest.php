<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RecordBrowseResult::class)]
/**
 * Proves the browse page honors the SDK page contract and its declared bounds.
 *
 * @since  2.0.0
 */
final class RecordBrowseResultTest extends TestCase
{
    /**
     * Prove the SDK page accessors disclose exactly the assembled page members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSdkPageAccessorsDiscloseExactlyTheAssembledPage(): void
    {
        $view = (new ReflectionClass(BusinessRecordView::class))->newInstanceWithoutConstructor();
        $page = new RecordBrowseResult([7 => $view], null, ['total' => '12.50']);

        self::assertSame([$view], $page->records());
        self::assertNull($page->nextCursor());
        self::assertSame(['total' => '12.50'], $page->aggregates());
    }

    /**
     * Prove a page beyond the declared record bound never reaches the delivery layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPageBeyondTheDeclaredBoundIsRefused(): void
    {
        $view = (new ReflectionClass(BusinessRecordView::class))->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds its declared bounds');

        new RecordBrowseResult(array_fill(0, 201, $view));
    }
}
