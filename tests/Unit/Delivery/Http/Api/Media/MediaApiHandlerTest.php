<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Media;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Media\MediaApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryMediaStorage;
use Kumwe\App\Tests\Support\MovableAuditClock;
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
 * Pins the media REST adapter to the administrator library's service, input closure and refusal shapes.
 *
 * Every case drives the real `MediaService` over an in-memory store, so authorization, the size ceiling and
 * the `media.upload` / `media.delete` audit events are the service's own. The adapter's job is only to turn
 * the request into arguments: a closed query vocabulary, a raw-body upload named by `filename`, a 404 problem
 * for an absent asset and a 422 problem for unusable input.
 *
 * @since  2.0.0
 */
#[CoversClass(MediaApiHandler::class)]
final class MediaApiHandlerTest extends TestCase
{
    /**
     * Smallest byte string the in-memory store accepts as a PNG.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PNG = "\x89PNG\r\n\x1a\n" . 'machine-parity-pixel';

    /**
     * Store the handler under test writes to.
     *
     * @var    InMemoryMediaStorage
     * @since  2.0.0
     */
    private InMemoryMediaStorage $storage;

    /**
     * Recorder the media service writes its audit events to.
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
        $this->storage = new InMemoryMediaStorage();
        $this->audit = new RecordingAuditRecorder();
    }

    /**
     * An upload stores the exact request body under the named file, audits it, and answers the asset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUploadStoresTheRawBodyAndAnswersTheCreatedAsset(): void
    {
        $response = $this->handle($this->request('POST', '/api/v1/media', ['filename' => 'logo.png'], self::PNG));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $asset = self::json($response);
        self::assertSame('logo.png', $asset['name']);
        self::assertSame('image/png', $asset['mime_type']);
        self::assertSame([self::PNG], array_values($this->storage->bytes));
        self::assertSame(['media.upload'], $this->audit->actions());
    }

    /**
     * Browse answers one page with the counters a client pages with, and read answers one asset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrowseAndReadAnswerTheLibrary(): void
    {
        $this->handle($this->request('POST', '/api/v1/media', ['filename' => 'first.png'], self::PNG));
        $this->handle($this->request('POST', '/api/v1/media', ['filename' => 'second.pdf'], '%PDF-1.7 body'));

        $page = self::json($this->handle(
            $this->request('GET', '/api/v1/media', ['kind' => 'image', 'per_page' => '5']),
        ));
        self::assertSame(1, $page['total']);
        self::assertSame(1, $page['page']);
        self::assertSame(1, $page['pages']);
        self::assertSame(5, $page['per_page']);
        self::assertIsArray($page['items']);
        self::assertSame('first.png', $page['items'][0]['name']);

        $id = array_key_first($this->storage->bytes);
        self::assertIsString($id);
        $read = $this->handle($this->request('GET', '/api/v1/media/' . $id, [], '', $id));
        self::assertSame(200, $read->getStatusCode());
        self::assertSame($id, self::json($read)['id']);
    }

    /**
     * An absent asset is a registered not-found problem, not an empty document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAbsentAssetIsANotFoundProblem(): void
    {
        $response = $this->handle($this->request('GET', '/api/v1/media/absent', [], '', 'absent'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('urn:kumwe:problem:media-not-found', self::json($response)['type']);
    }

    /**
     * Delete removes the asset and audits it; deleting again is the browser's harmless repeat.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeleteRemovesAndAuditsAndARepeatIsHarmless(): void
    {
        $this->handle($this->request('POST', '/api/v1/media', ['filename' => 'gone.png'], self::PNG));
        $id = array_key_first($this->storage->bytes);
        self::assertIsString($id);

        $deleted = $this->handle($this->request('DELETE', '/api/v1/media/' . $id, [], '', $id));
        $again = $this->handle($this->request('DELETE', '/api/v1/media/' . $id, [], '', $id));

        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame(204, $again->getStatusCode());
        self::assertSame([], $this->storage->bytes);
        self::assertSame(['media.upload', 'media.delete'], $this->audit->actions());
    }

    /**
     * Unusable input is a validation problem the service never sees, and a refused file is one too.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnusableInputIsAValidationProblem(): void
    {
        $cases = [
            $this->request('GET', '/api/v1/media', ['sort' => 'name']),
            $this->request('GET', '/api/v1/media', ['kind' => 'video']),
            $this->request('GET', '/api/v1/media', ['per_page' => '97']),
            $this->request('GET', '/api/v1/media', ['page' => '0']),
            $this->request('GET', '/api/v1/media', ['q' => str_repeat('x', 201)]),
            $this->request('POST', '/api/v1/media', [], self::PNG),
            $this->request('POST', '/api/v1/media', ['filename' => ' '], self::PNG),
            $this->request('POST', '/api/v1/media', ['filename' => 'a.png', 'kind' => 'image'], self::PNG),
            $this->request('POST', '/api/v1/media', ['filename' => 'empty.png'], ''),
            $this->request('POST', '/api/v1/media', ['filename' => 'script.txt'], 'plain text'),
            $this->request('PATCH', '/api/v1/media/x', [], '', 'x'),
        ];
        foreach ($cases as $index => $request) {
            $response = $this->handle($request);
            self::assertSame(422, $response->getStatusCode(), 'case ' . $index);
            self::assertSame('urn:kumwe:problem:validation-failed', self::json($response)['type']);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
        self::assertSame([], $this->storage->bytes);
        self::assertSame([], $this->audit->actions());
    }

    /**
     * A credential without the service's capability is refused by the service itself and nothing is stored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheServiceAuthorizationRefusalPropagates(): void
    {
        $this->expectException(AuthorizationDenied::class);
        try {
            $this->handle(
                $this->request('POST', '/api/v1/media', ['filename' => 'logo.png'], self::PNG),
                ['content.read'],
            );
        } finally {
            self::assertSame([], $this->storage->bytes);
        }
    }

    /**
     * Run one request through a handler over the real media service.
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
        array $capabilities = ['content.read', 'content.update', 'content.delete'],
    ): ResponseInterface {
        $principal = AuthorizationContext::principal($capabilities);
        $request = $request
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'media-api-test-' . bin2hex(random_bytes(4)),
            ));
        $media = new MediaService(
            $this->storage,
            AuthorizationContext::gateway(),
            $this->audit,
            new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
            1_000_000,
        );

        return (new MediaApiHandler(
            $media,
            new ProblemDetailsResponseFactory(),
            sys_get_temp_dir() . '/kumwe-media-api-test',
        ))->handle($request);
    }

    /**
     * Build one API request with a query, a body and an optional routed media identifier.
     *
     * @param   string                 $method  HTTP method.
     * @param   string                 $path    Request path.
     * @param   array<string, string>  $query   Query parameters.
     * @param   string                 $body    Raw request body.
     * @param   ?string                $id      Routed `mediaId` attribute.
     *
     * @return  ServerRequestInterface  Request.
     *
     * @since   2.0.0
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        ?string $id = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test' . $path)
            ->withQueryParams($query)
            ->withBody((new StreamFactory())->createStream($body));

        return $id === null ? $request : $request->withAttribute('mediaId', $id);
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
