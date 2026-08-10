<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessRecordApiRequest;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessRecordPreconditionFailed;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(BusinessRecordApiRequest::class)]
#[CoversClass(BusinessRecordPreconditionFailed::class)]
/**
 * Proves generated-business REST input is bounded and trusts only middleware-parsed guards.
 *
 * @since  2.0.0
 */
final class BusinessRecordApiRequestTest extends TestCase
{
    /**
     * Proves object values remain distinct from lists and exact scalar strings are preserved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeepsJsonObjectsDistinctFromListsAndNormalizesExactValues(): void
    {
        $body = BusinessRecordApiRequest::json($this->request(
            '{"values":{"amount":"10.00","metadata":{"priority":true},"tags":["one","two"]}}',
        ));

        self::assertSame([
            'amount' => '10.00',
            'metadata' => ['priority' => true],
            'tags' => ['one', 'two'],
        ], BusinessRecordApiRequest::object($body, 'values'));
        $this->expectException(InvalidArgumentException::class);

        BusinessRecordApiRequest::object(
            BusinessRecordApiRequest::json($this->request('{"values":[]}')),
            'values',
        );
    }

    /**
     * Proves transport documents reject fields outside their closed operation vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnknownOperationFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field');

        BusinessRecordApiRequest::keys(['values' => [], 'organization' => 'untrusted'], ['values'], 'create body');
    }

    /**
     * Proves query normalization changes only explicitly typed transport controls.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNormalizesOnlyDeclaredQueryScalars(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/records/core.invoice')
            ->withQueryParams([
                'page_size' => '25',
                'include_archived' => '1',
                'filter' => [
                    'type' => 'comparison',
                    'field' => 'amount',
                    'operator' => 'eq',
                    'value' => '00025',
                ],
            ]);

        self::assertSame([
            'page_size' => 25,
            'include_archived' => true,
            'filter' => [
                'type' => 'comparison',
                'field' => 'amount',
                'operator' => 'eq',
                'value' => '00025',
            ],
        ], BusinessRecordApiRequest::query($request));
    }

    /**
     * Proves oversized filter trees are refused at the transport boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAFilterBeforeItExceedsTheSixtyFourNodeBudget(): void
    {
        $children = array_fill(0, 64, [
            'type' => 'null',
            'field' => 'reference',
            'is_null' => 'true',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/records/core.invoice')
            ->withQueryParams(['filter' => [
                'type' => 'boolean',
                'operator' => 'all',
                'children' => $children,
            ]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filter exceeds its safe bounds');

        BusinessRecordApiRequest::query($request);
    }

    /**
     * Proves idempotency and version input comes only from validated middleware attributes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRequiresMiddlewareParsedIdempotencyAndIfMatchAttributes(): void
    {
        $request = $this->request('{}')
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('record-update-0001'),
            )
            ->withHeader('If-Match', '"v7"')
            ->withAttribute(RequireIfMatchMiddleware::ATTRIBUTE, IfMatch::fromHeader('"v7"'));

        self::assertSame('record-update-0001', (string) BusinessRecordApiRequest::idempotencyKey($request));
        self::assertSame(7, BusinessRecordApiRequest::expectedVersion($request));

        $wildcard = $request
            ->withHeader('If-Match', '*')
            ->withAttribute(RequireIfMatchMiddleware::ATTRIBUTE, IfMatch::fromHeader('*'));

        $this->expectException(BusinessRecordPreconditionFailed::class);
        BusinessRecordApiRequest::expectedVersion($wildcard);
    }

    /**
     * Proves an unparsed raw idempotency header cannot bypass its middleware guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDoesNotFallBackToAValidRawIdempotencyHeader(): void
    {
        $request = $this->request('{}')->withHeader('Idempotency-Key', 'record-update-0001');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parsed Idempotency-Key');

        BusinessRecordApiRequest::idempotencyKey($request);
    }

    /**
     * Proves request parsing enforces the declared one-megabyte body limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnActuallyOversizedBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds 1048576 bytes');

        BusinessRecordApiRequest::json($this->request('{"value":"' . str_repeat('x', 1_048_576) . '"}'));
    }

    /**
     * Build one JSON request for transport parsing assertions.
     *
     * @param   string  $json  Exact request body bytes.
     *
     * @return  ServerRequestInterface  Request carrying the supplied body.
     *
     * @since   2.0.0
     */
    private function request(string $json): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/records/core.invoice')
            ->withBody((new StreamFactory())->createStream($json));
    }
}
