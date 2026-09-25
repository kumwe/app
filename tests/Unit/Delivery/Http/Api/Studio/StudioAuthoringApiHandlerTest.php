<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Studio;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringApiHandler;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringProblemMapper;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\OpenApi\Application\ProblemDetailsRegistry;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

/**
 * Pins the REST face of Studio authoring: registered problem types per category and closed request bodies.
 *
 * Every Producer category maps to one registered `studio-authoring-*` type with Producer's status, and the
 * category, diagnostics and revision travel as the type's closed extensions. A malformed, unknown or
 * over-wide request body is refused with the same invalid-request diagnostic before the gateway runs.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioAuthoringApiHandler::class)]
#[CoversClass(StudioAuthoringProblemMapper::class)]
#[CoversClass(ProblemDetailsRegistry::class)]
final class StudioAuthoringApiHandlerTest extends TestCase
{
    /**
     * Each category, and both replay outcomes, publish one registered type with the category's status.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCategoryMapsToItsRegisteredProblemType(): void
    {
        $mapper = new StudioAuthoringProblemMapper(new ProblemDetailsResponseFactory());
        $cases = [
            ['invalid-request', 'studio.machine/request-invalid', 400, 'studio-authoring-invalid-request'],
            ['incompatible', 'studio.host/incompatible', 400, 'studio-authoring-invalid-request'],
            ['cancelled', 'studio.host/cancelled', 400, 'studio-authoring-invalid-request'],
            ['unauthenticated', 'studio.host/unauthenticated', 401, 'studio-authoring-unauthenticated'],
            ['forbidden', 'studio.host/session-refused', 403, 'studio-authoring-forbidden'],
            ['not-found', 'studio.authoring/type-not-found', 404, 'studio-authoring-not-found'],
            ['conflict', 'studio.authoring/expected-mismatch', 409, 'studio-authoring-conflict'],
            ['limit-exceeded', 'studio.host/limit', 413, 'studio-authoring-limit-exceeded'],
            ['validation-failed', 'studio.authoring/unknown-target', 422, 'studio-authoring-validation-failed'],
            ['rate-limited', 'studio.host/rate', 429, 'studio-authoring-rate-limited'],
            ['internal', 'studio.authoring/document-invalid', 500, 'studio-authoring-internal'],
            ['unavailable', 'studio.host/unavailable', 503, 'studio-authoring-unavailable'],
            [
                'invalid-request',
                'studio.host/idempotency-intent-changed',
                422,
                'studio-authoring-idempotency-key-reused',
            ],
            ['unavailable', 'studio.host/idempotency-in-progress', 409, 'studio-authoring-idempotency-in-progress'],
        ];
        foreach ($cases as [$category, $diagnostic, $status, $type]) {
            $response = $mapper->problem(
                StudioMachineAuthoringRefused::of($category, $diagnostic, $category === 'conflict' ? 'entry-r2' : null),
                'https://kumwe.test/api/v1/studio/authoring/save-item',
            );
            $body = self::body($response);
            self::assertSame($status, $response->getStatusCode(), $diagnostic);
            self::assertSame('urn:kumwe:problem:' . $type, $body['type'], $diagnostic);
            self::assertSame($category, $body['studio_category']);
            self::assertSame([$diagnostic], $body['studio_diagnostics']);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            if ($category === 'conflict') {
                self::assertSame('entry-r2', $body['studio_revision']);
            } else {
                self::assertArrayNotHasKey('studio_revision', $body);
            }
        }
    }

    /**
     * Malformed, unknown and over-wide bodies are refused before the gateway runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedRequestsAreRefusedAsInvalidRequests(): void
    {
        $handler = new StudioAuthoringApiHandler(
            new StudioMachineAuthoringGateway(
                self::bare(ContentStudioAuthoringContextAuthority::class),
                self::bare(StudioHostSessionAuthority::class),
                self::bare(ContentStudioAuthoringTargetResolver::class),
                self::bare(ContentService::class),
                self::bare(ContentModelService::class),
                self::bare(StudioProducerHostFactory::class),
            ),
            new StudioAuthoringProblemMapper(new ProblemDetailsResponseFactory()),
        );
        $cases = [
            'not json' => ['sessions', '{', 'studio.machine/request-invalid'],
            'not an object' => ['sessions', '[]', 'studio.machine/request-invalid'],
            'unknown intent' => ['sessions', '{"intent":"publish"}', 'studio.machine/target-invalid'],
            'extra open member' => ['sessions', '{"intent":"create","actor":"x"}', 'studio.machine/request-invalid'],
            'typed member' => [
                'sessions',
                '{"intent":"create","content_type_version":"2"}',
                'studio.machine/request-invalid',
            ],
            'string member' => ['sessions', '{"intent":"create","content_id":7}', 'studio.machine/request-invalid'],
            'unknown operation' => ['publish', '{}', 'studio.machine/operation-unknown'],
            'no argument' => [
                'plan-save',
                '{"session":"contexts/k","session_generation":"g"}',
                'studio.machine/request-invalid',
            ],
            'extra member' => [
                'plan-save',
                '{"session":"contexts/k","session_generation":"g","argument":{},"actor":"x"}',
                'studio.machine/request-invalid',
            ],
            'oversized' => ['sessions', str_repeat(' ', 1048577), 'studio.machine/request-invalid'],
        ];
        foreach ($cases as $label => [$path, $body, $diagnostic]) {
            $response = $handler->handle(self::request($path, $body));
            self::assertSame(400, $response->getStatusCode(), $label);
            self::assertSame([$diagnostic], self::body($response)['studio_diagnostics'], $label);
        }

        $unauthenticated = $handler->handle(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://kumwe.test/api/v1/studio/authoring/sessions',
            ),
        );
        self::assertSame(400, $unauthenticated->getStatusCode());
    }

    /**
     * Build one authenticated API request.
     *
     * @param   string  $path  Path after the authoring prefix.
     * @param   string  $body  Raw body.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Request carrying matching principal and context.
     *
     * @since   2.0.0
     */
    private static function request(string $path, string $body): \Psr\Http\Message\ServerRequestInterface
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read'],
            'api-token:rest',
        );
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'rest-request',
            surface: AuthenticatedSurface::Api,
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test' . StudioAuthoringApiHandler::PREFIX . $path)
            ->withBody((new StreamFactory())->createStream($body))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Decode one JSON response body.
     *
     * @param   ResponseInterface  $response  Response to decode.
     *
     * @return  array<string, mixed>  Decoded body.
     *
     * @since   2.0.0
     */
    private static function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * An uninitialized collaborator the refusals under test must never reach.
     *
     * @template T of object
     *
     * @param   class-string<T>  $class  Collaborator class.
     *
     * @return  T  Instance without a constructor run.
     *
     * @since   2.0.0
     */
    private static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
