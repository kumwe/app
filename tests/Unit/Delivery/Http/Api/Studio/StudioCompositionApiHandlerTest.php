<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Studio;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\Studio\StudioCompositionApiHandler;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins `/api/v1/content-types/{id}/versions/{version}/composition` to the composition screen's service.
 *
 * The real `StudioContentCompositionService` runs over in-memory bindings and artifacts, so the provisioned
 * draft, its locks and its audit event are the service's; the handler's job is the coordinate check, the 404
 * before provisioning and for an unavailable model, the screen's 409 for a stale theme lock and the no-store
 * machine document.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionApiHandler::class)]
#[CoversClass(StudioContentComposition::class)]
final class StudioCompositionApiHandlerTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * Read before provisioning is a 404, provisioning answers the draft once and reads and re-provisions echo it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProvisionAnswersTheDraftAndReadsEchoIt(): void
    {
        $audit = new RecordingAuditRecorder();
        $handler = new StudioCompositionApiHandler(
            $this->compositionService($audit),
            new ProblemDetailsResponseFactory(),
        );

        $missing = $handler->handle($this->request('GET', self::$compositionTypeId, '4'));
        $provisioned = $handler->handle($this->request('POST', self::$compositionTypeId, '4'));
        $read = $handler->handle($this->request('GET', self::$compositionTypeId, '4'));
        $again = $handler->handle($this->request('POST', self::$compositionTypeId, '4'));

        self::assertProblem($missing, 404, 'urn:kumwe:problem:studio-composition-not-found');
        self::assertSame(200, $provisioned->getStatusCode());
        self::assertSame('no-store', $provisioned->getHeaderLine('Cache-Control'));
        $document = json_decode((string) $provisioned->getBody(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(self::$compositionTypeId, $document['content_type_id']);
        self::assertSame(4, $document['content_type_version']);
        self::assertSame(1, $document['binding_revision']);
        self::assertSame('blueprint', $document['blueprint']['kind']);
        self::assertSame('draft', $document['blueprint']['status']);
        self::assertSame('1.0.0', $document['blueprint']['version']);
        self::assertSame([], $document['blueprint']['document']['roots']);
        self::assertSame((string) $provisioned->getBody(), (string) $read->getBody());
        self::assertSame((string) $provisioned->getBody(), (string) $again->getBody());
        self::assertSame(['studio.composition.provision'], $audit->actions());
    }

    /**
     * A malformed coordinate is a 422, an unknown model a 404 and a stale theme lock the screen's 409.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoordinateModelAndThemeRefusalsUseRegisteredProblems(): void
    {
        $audit = new RecordingAuditRecorder();
        $handler = new StudioCompositionApiHandler(
            $this->compositionService($audit),
            new ProblemDetailsResponseFactory(),
        );
        foreach (['0', '01', 'four', '1234567890'] as $version) {
            self::assertProblem(
                $handler->handle($this->request('GET', self::$compositionTypeId, $version)),
                422,
                'urn:kumwe:problem:validation-failed',
            );
        }
        self::assertProblem(
            $handler->handle($this->request('POST', '018f22e2-7c8b-7ab0-8f3a-88e8026be999', '4')),
            404,
            'urn:kumwe:problem:studio-composition-not-found',
        );
        self::assertProblem(
            $handler->handle($this->request('GET', self::$compositionTypeId, '5')),
            404,
            'urn:kumwe:problem:studio-composition-not-found',
        );
        self::assertSame(200, $handler->handle($this->request('POST', self::$compositionTypeId, '4'))->getStatusCode());

        $this->retheme();

        self::assertProblem(
            $handler->handle($this->request('GET', self::$compositionTypeId, '4')),
            409,
            'urn:kumwe:problem:studio-composition-theme-mismatch',
        );
        self::assertProblem(
            $handler->handle($this->request('POST', self::$compositionTypeId, '4')),
            409,
            'urn:kumwe:problem:studio-composition-theme-mismatch',
        );
        self::assertSame(['studio.composition.provision'], $audit->actions());
    }

    /**
     * Build one routed, authenticated request at a composition coordinate.
     *
     * @param   string  $method       `GET` or `POST`.
     * @param   string  $contentType  Routed Content type identifier.
     * @param   string  $version      Routed version segment.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Request past the capability pre-flight.
     *
     * @since   2.0.0
     */
    private function request(
        string $method,
        string $contentType,
        string $version,
    ): \Psr\Http\Message\ServerRequestInterface {
        $principal = AuthorizationContext::principal(
            ['content.read', 'studio.mode.blueprint'],
            '018f22e2-7c8b-7ab0-8f3a-88e8026be902',
        );

        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                'https://kumwe.test/api/v1/content-types/' . $contentType . '/versions/' . $version . '/composition',
            )
            ->withAttribute('id', $contentType)
            ->withAttribute('version', $version)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(
                ExecutionContextAttribute::NAME,
                $principal->context(
                    SiteContext::default(),
                    AuthenticationStrength::BearerToken,
                    'composition-api-test',
                ),
            );
    }

    /**
     * Assert one no-store problem document of the given status and type.
     *
     * @param   ResponseInterface  $response  Handler response.
     * @param   int                $status    Expected status.
     * @param   string             $type      Expected problem type.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertProblem(ResponseInterface $response, int $status, string $type): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $problem = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($type, $problem['type']);
    }
}
