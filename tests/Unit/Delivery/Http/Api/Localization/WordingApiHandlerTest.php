<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Localization;

use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Localization\WordingApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryWording;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pins the wording REST adapter to the administrator Wording screen's service and refusal vocabulary.
 *
 * The real `MessageOverrideService` runs over an in-memory store, so the capability, layer, locale,
 * identifier-grammar and ICU rules are the service's; the adapter's closed query and body vocabulary, and the
 * translation of every input refusal into one 422 problem, are what is pinned here.
 *
 * @since  2.0.0
 */
#[CoversClass(WordingApiHandler::class)]
final class WordingApiHandlerTest extends TestCase
{
    /**
     * Store the service writes to.
     *
     * @var    InMemoryWording
     * @since  2.0.0
     */
    private InMemoryWording $wording;

    /**
     * Recorder the service writes its audit events to.
     *
     * @var    RecordingAuditRecorder
     * @since  2.0.0
     */
    private RecordingAuditRecorder $audit;

    /**
     * Build a fresh store and recorder for each case.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->wording = new InMemoryWording();
        $this->audit = new RecordingAuditRecorder();
    }

    /**
     * Saving, listing, searching and withdrawing run through the service and audit each write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveListSearchAndWithdrawRunThroughTheService(): void
    {
        $saved = $this->handle($this->request('PUT', '/api/v1/wording/overrides', [], [
            'layer' => 'site',
            'locale' => 'en-GB',
            'identifier' => InMemoryWording::IDENTIFIER,
            'pattern' => 'Customer',
        ]));
        self::assertSame(200, $saved->getStatusCode());
        self::assertSame('Customer', self::json($saved)['pattern']);

        $listed = self::json($this->handle($this->request('GET', '/api/v1/wording/overrides', ['locale' => 'en-GB'])));
        self::assertSame('site', $listed['layer']);
        self::assertIsArray($listed['items']);
        self::assertSame(
            [InMemoryWording::IDENTIFIER],
            array_column($listed['items'], 'identifier'),
        );

        $found = self::json($this->handle($this->request('GET', '/api/v1/wording/catalogue', [
            'locale' => 'en-GB',
            'q' => 'client',
            'limit' => '10',
        ])));
        self::assertIsArray($found['items']);
        self::assertSame([InMemoryWording::IDENTIFIER], array_column($found['items'], 'identifier'));

        $withdraw = [
            'layer' => 'site',
            'locale' => 'en-GB',
            'identifier' => InMemoryWording::IDENTIFIER,
        ];
        $first = self::json($this->handle($this->request('POST', '/api/v1/wording/overrides/withdraw', [], $withdraw)));
        $again = self::json($this->handle($this->request('POST', '/api/v1/wording/overrides/withdraw', [], $withdraw)));
        self::assertTrue($first['withdrawn']);
        self::assertFalse($again['withdrawn']);
        self::assertSame(
            ['localization.override.write', 'localization.override.withdraw'],
            $this->audit->actions(),
        );
    }

    /**
     * Every unusable query, body, identifier or pattern is one 422 problem and nothing is stored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnusableInputIsAValidationProblem(): void
    {
        $valid = ['layer' => 'site', 'locale' => 'en-GB', 'identifier' => InMemoryWording::IDENTIFIER];
        $cases = [
            $this->request('GET', '/api/v1/wording/overrides', ['site' => 'default']),
            $this->request('GET', '/api/v1/wording/overrides', ['layer' => 'core']),
            $this->request('GET', '/api/v1/wording/catalogue', ['q' => 'client']),
            $this->request('GET', '/api/v1/wording/catalogue', ['locale' => 'en-GB', 'q' => 'x', 'limit' => '201']),
            $this->request('PUT', '/api/v1/wording/overrides', [], [...$valid, 'pattern' => 'A', 'note' => 'x']),
            $this->request('PUT', '/api/v1/wording/overrides', [], $valid),
            $this->request('PUT', '/api/v1/wording/overrides', [], [...$valid, 'pattern' => 'Broken {']),
            $this->request('PUT', '/api/v1/wording/overrides', [], [
                ...$valid,
                'identifier' => 'Not An Identifier',
                'pattern' => 'A',
            ]),
            $this->request('PUT', '/api/v1/wording/overrides', [], [...$valid, 'layer' => 'core', 'pattern' => 'A']),
            $this->request('POST', '/api/v1/wording/overrides/withdraw', [], ['layer' => 'site']),
            $this->request('POST', '/api/v1/wording/overrides/withdraw', [], null, '[1, 2]'),
            $this->request('POST', '/api/v1/wording/overrides/withdraw', [], null, '{broken'),
            $this->request('DELETE', '/api/v1/wording/overrides'),
        ];
        foreach ($cases as $index => $request) {
            $response = $this->handle($request);
            self::assertSame(422, $response->getStatusCode(), 'case ' . $index);
            self::assertSame('urn:kumwe:problem:validation-failed', self::json($response)['type'], 'case ' . $index);
        }
        self::assertSame([], $this->wording->overrides(
            \Kumwe\Localization\Domain\MessageCatalogueLayer::Site,
            SiteContext::DEFAULT,
        ));
    }

    /**
     * A credential without `localization.overrides.manage` is refused by the service itself.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheServiceAuthorizationRefusalPropagates(): void
    {
        $this->expectException(AuthorizationDenied::class);

        $this->handle($this->request('GET', '/api/v1/wording/overrides'), ['content.read']);
    }

    /**
     * Run one request through a handler over the real wording service.
     *
     * @param   ServerRequestInterface  $request       Request to handle.
     * @param   list<string>            $capabilities  Capabilities the bearer principal holds.
     *
     * @return  ResponseInterface  Handler response.
     *
     * @since   2.0.0
     */
    private function handle(
        ServerRequestInterface $request,
        array $capabilities = ['localization.overrides.manage'],
    ): ResponseInterface {
        $principal = AuthorizationContext::principal($capabilities);
        $request = $request
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'wording-api-test-' . bin2hex(random_bytes(4)),
            ));

        return (new WordingApiHandler($this->wording->service($this->audit), new ProblemDetailsResponseFactory()))
            ->handle($request);
    }

    /**
     * Build one API request.
     *
     * @param   string                     $method  HTTP method.
     * @param   string                     $path    Request path.
     * @param   array<string, string>      $query   Query parameters.
     * @param   ?array<string, mixed>      $body    JSON body, or null for none.
     * @param   ?string                    $raw     Raw body replacing the JSON body.
     *
     * @return  ServerRequestInterface  Request.
     *
     * @since   2.0.0
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $raw = null,
    ): ServerRequestInterface {
        $content = $raw ?? ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test' . $path)
            ->withQueryParams($query)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($content));
    }

    /**
     * Decode one JSON response body.
     *
     * @param   ResponseInterface  $response  Response to decode.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
