<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Studio;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringProblemMapper;
use Kumwe\App\Delivery\Http\Api\Studio\StudioCompositionSessionApiHandler;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Idempotency\IdempotencyKey;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

/**
 * Pins the closed REST grammar of `/api/v1/studio/composition/*` and its Studio problem documents.
 *
 * The real gateway runs over the real composition service and in-memory stores, with a session authority and host
 * factory that these requests must never reach: a malformed or undeclared body, an unknown mode or operation, a
 * missing argument, a missing replay key, an unprovisioned composition and an unauthenticated request are each
 * answered with the Studio authoring problem type the authoring routes use.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionSessionApiHandler::class)]
final class StudioCompositionSessionApiHandlerTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * Every malformed or refused request answers the matching Studio problem before the host is reached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsAnswerStudioProblemsBeforeTheHost(): void
    {
        $handler = new StudioCompositionSessionApiHandler(
            new StudioMachineCompositionGateway(
                $this->compositionService(new RecordingAuditRecorder()),
                (new ReflectionClass(StudioHostSessionAuthority::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor(),
            ),
            new StudioAuthoringProblemMapper(new ProblemDetailsResponseFactory()),
        );
        $operation = [
            'session' => 'contexts/key',
            'session_generation' => 'session-one',
            'argument' => new \stdClass(),
        ];
        $open = ['content_type_id' => 'x', 'content_type_version' => 1];
        $cases = [
            'malformed' => ['sessions', '{', 400, 'invalid-request'],
            'list body' => ['sessions', '[]', 400, 'invalid-request'],
            'undeclared member' => ['sessions', [...$open, 'x' => 1], 400, 'invalid-request'],
            'unknown mode' => ['sessions', [...$open, 'mode' => 'content'], 400, 'invalid-request'],
            'string version' => ['sessions', [...$open, 'content_type_version' => '1'], 400, 'invalid-request'],
            'not provisioned' => [
                'sessions',
                ['content_type_id' => self::$compositionTypeId, 'content_type_version' => 4],
                404,
                'not-found',
            ],
            'unknown operation' => ['retire', $operation, 400, 'invalid-request'],
            'no argument object' => ['load', [...$operation, 'argument' => 'x'], 400, 'invalid-request'],
            'typed member' => ['load', [...$operation, 'locale' => 7], 400, 'invalid-request'],
            'no replay key' => ['save', [...$operation, 'expected_revision' => 'initial-a'], 400, 'invalid-request'],
        ];
        foreach ($cases as $label => [$name, $body, $status, $category]) {
            $response = $handler->handle($this->request($name, $body));
            $problem = json_decode((string) $response->getBody(), true);

            self::assertSame($status, $response->getStatusCode(), $label);
            self::assertIsArray($problem, $label);
            self::assertSame($category, $problem['studio_category'], $label);
        }

        $keyed = $handler->handle($this->request('load', $operation, 'replay-key-0001'));
        $unauthenticated = $handler->handle($this->request('sessions', [], null, false));

        self::assertSame(
            ['studio.machine/idempotency-key-unexpected'],
            json_decode((string) $keyed->getBody(), true)['studio_diagnostics'],
        );
        self::assertSame(400, $unauthenticated->getStatusCode());
    }

    /**
     * Build one composition request.
     *
     * @param   string               $name           Path segment after the prefix.
     * @param   array<mixed>|string  $body           JSON document or raw body.
     * @param   ?string              $key            Idempotency key the middleware would have attached.
     * @param   bool                 $authenticated  Whether a principal and context are attached.
     *
     * @return  ServerRequestInterface  Request.
     *
     * @since   2.0.0
     */
    private function request(
        string $name,
        array|string $body,
        ?string $key = null,
        bool $authenticated = true,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test' . StudioCompositionSessionApiHandler::PREFIX . $name)
            ->withBody((new StreamFactory())->createStream(
                is_string($body) ? $body : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
            ));
        if ($key !== null) {
            $request = $request->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromString($key),
            );
        }
        if (!$authenticated) {
            return $request;
        }
        $principal = AuthorizationContext::principal(
            ['content.read', 'studio.mode.blueprint'],
            '018f22e2-7c8b-7ab0-8f3a-88e8026be903',
        );

        return $request
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'composition-session-api-test',
                surface: AuthenticatedSurface::Api,
            ));
    }
}
