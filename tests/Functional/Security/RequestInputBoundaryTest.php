<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Administrator\Http\Handler\AdministratorContentListHandler;
use Kumwe\App\Delivery\Http\Api\Automation\AutomationApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Http\Middleware\BodyLimitMiddleware;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins how the live pipeline answers oversized, pathological, out-of-range and probing inputs.
 *
 * Exhaustion inputs must be refused before they cost work and never surface as a server error; page and
 * limit parameters are enforced at their declared bounds rather than silently clamped or overflowed; and an
 * identifier that is missing, malformed or merely forbidden must produce the same answer, so the machine
 * surface cannot be used to enumerate what exists. Every request enters through the production pipeline.
 *
 * @since  2.0.0
 */
#[CoversClass(BodyLimitMiddleware::class)]
#[CoversClass(RequireIdempotencyKeyMiddleware::class)]
#[CoversClass(AutomationApiHandler::class)]
#[CoversClass(BusinessApprovalApiHandler::class)]
#[CoversClass(AdministratorContentListHandler::class)]
#[CoversClass(MediaService::class)]
final class RequestInputBoundaryTest extends TestCase
{
    /**
     * Oversized, deeply nested and malformed inputs are refused with a client error, never a server error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedAndPathologicalInputsAreRefusedBeforeWork(): void
    {
        $harness = SecurityHttpHarness::boot();
        $token = $harness->machineActor(['navigation.manage'])['token'];
        $streams = new StreamFactory();
        $oversized = str_repeat('a', 2_097_153);

        $anonymous = $harness->handle(
            $harness->request('POST', '/administrator/login')->withBody($streams->createStream($oversized)),
        );
        self::assertSame(413, $anonymous->getStatusCode(), 'An anonymous oversized body is refused.');
        $authenticated = $harness->handle(
            $harness->api('POST', '/api/v1/menus', $token, null, ['Idempotency-Key' => 'oversized-' . $this->key()])
                ->withHeader('Content-Type', 'application/json')
                ->withBody($streams->createStream('{"handle":"' . $oversized . '"}')),
        );
        self::assertSame(413, $authenticated->getStatusCode(), 'An authenticated oversized body is refused.');

        $deep = str_repeat('{"a":', 4096) . '1' . str_repeat('}', 4096);
        $nested = $harness->handle(
            $harness->api('POST', '/api/v1/menus', $token, null, ['Idempotency-Key' => 'nested-' . $this->key()])
                ->withHeader('Content-Type', 'application/json')
                ->withBody($streams->createStream($deep)),
        );
        self::assertSame(422, $nested->getStatusCode(), 'A nesting bomb is a validation failure, not a crash.');

        foreach ([str_repeat('k', 129), 'short', 'contains spaces here'] as $key) {
            $refused = $harness->handle($harness->api(
                'POST',
                '/api/v1/menus',
                $token,
                ['handle' => 'never_' . bin2hex(random_bytes(6)), 'title' => 'Never created'],
                ['Idempotency-Key' => $key],
            ));
            self::assertSame(400, $refused->getStatusCode(), 'An unusable Idempotency-Key is refused.');
            self::assertSame('urn:kumwe:problem:invalid-idempotency-key', $this->problemType($refused));
        }
    }

    /**
     * Page and limit parameters are enforced at their declared bounds, including integer overflow.
     *
     * The machine surface refuses an out-of-range limit by name. The administrator browsers, whose query
     * string is anyone's hand-edited URL, fall back to a neutral listing instead of failing with a server
     * error that also writes an error-level log line per request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPageAndLimitBoundsAreEnforcedRatherThanClampedOrOverflowed(): void
    {
        $harness = SecurityHttpHarness::boot();
        $automation = $harness->machineActor(['automation.manage'])['token'];
        $reader = $harness->machineActor(['content.read'])['token'];

        $bounded = $harness->handle($harness->api('GET', '/api/v1/jobs?limit=500', $automation));
        self::assertSame(200, $bounded->getStatusCode());
        foreach (['0', '-1', '501', '99999999999999999999', 'abc', '1e3', ' 5'] as $limit) {
            $path = '/api/v1/jobs?limit=' . rawurlencode($limit);
            $response = $harness->handle($harness->api('GET', $path, $automation));
            self::assertSame(422, $response->getStatusCode(), 'Job list limit ' . $limit);
        }
        foreach (['0', '101', '99999999999999999999', '-5'] as $limit) {
            $response = $harness->handle(
                $harness->api('GET', '/api/v1/business/approvals?limit=' . rawurlencode($limit), $reader),
            );
            self::assertSame(422, $response->getStatusCode(), 'Approval inbox limit ' . $limit);
        }
        $smuggled = $harness->handle($harness->api('GET', '/api/v1/business/approvals?offset=1000000', $reader));
        self::assertSame(422, $smuggled->getStatusCode(), 'An undeclared paging parameter is refused.');

        $cookie = $harness->administratorCookie();
        foreach (
            [
                '/administrator/content?page=99999999999999999999',
                '/administrator/content?page=100001',
                '/administrator/content?per_page=1000000',
                '/administrator/content?sort=' . rawurlencode("title_asc'; DROP TABLE x"),
                '/administrator/content?type=not-a-type&status=' . str_repeat('s', 200),
                '/administrator/content?q=' . str_repeat('q', 161),
                '/administrator/media?page=9223372036854775807',
                '/administrator/media?page=99999999999999999999&kind=image',
            ] as $path
        ) {
            $page = $harness->handle($harness->request('GET', $path)->withCookieParams($cookie));
            self::assertSame(200, $page->getStatusCode(), 'A hand-edited query is not a server fault: ' . $path);
        }
    }

    /**
     * Missing, malformed and forbidden identifiers are answered identically on the machine surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingMalformedAndForbiddenIdentifiersAreIndistinguishable(): void
    {
        $harness = SecurityHttpHarness::boot();
        $navigator = $harness->machineActor(['navigation.manage'])['token'];
        $outsider = $harness->machineActor(['content.read'])['token'];
        $created = $harness->handle($harness->api(
            'POST',
            '/api/v1/menus',
            $navigator,
            ['handle' => 'probe_' . bin2hex(random_bytes(6)), 'title' => 'Probe target'],
            ['Idempotency-Key' => 'probe-' . bin2hex(random_bytes(6))],
        ));
        self::assertSame(201, $created->getStatusCode());
        $decoded = json_decode((string) $created->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['id'] ?? null);
        $existing = $decoded['id'];

        $answers = [];
        foreach ([$existing, '018f22e2-7c8b-7ab0-8f3a-88e8026bb999', 'not-an-identifier'] as $identifier) {
            foreach (['/api/v1/menus/%s', '/api/v1/menus/%s/items', '/api/v1/menu-items/%s'] as $pattern) {
                $path = sprintf($pattern, $identifier);
                $response = $harness->handle($harness->api('GET', $path, $outsider));
                $answers[$path] = [$response->getStatusCode(), $this->problemType($response)];
            }
        }
        self::assertSame(
            [[403, 'about:blank']],
            array_values(array_unique($answers, SORT_REGULAR)),
            'An outsider learns nothing about which identifiers exist.',
        );

        $missing = $harness->handle(
            $harness->api('GET', '/api/v1/menus/018f22e2-7c8b-7ab0-8f3a-88e8026bb999', $navigator),
        );
        $malformed = $harness->handle($harness->api('GET', '/api/v1/menus/not-an-identifier', $navigator));
        self::assertSame($missing->getStatusCode(), $malformed->getStatusCode());
        self::assertSame($this->problemType($missing), $this->problemType($malformed));
    }

    /**
     * Mint a fresh idempotency-key suffix.
     *
     * @return  string  Twelve lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private function key(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * Read the problem type of a problem document, or an empty string for any other body.
     *
     * @param   ResponseInterface  $response  Response to inspect.
     *
     * @return  string  Problem type URI.
     *
     * @since   2.0.0
     */
    private function problemType(ResponseInterface $response): string
    {
        $decoded = json_decode((string) $response->getBody(), true, 16);

        return is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : '';
    }
}
