<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Localization;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves wording overrides to bearer callers through the `MessageOverrideService` the administrator uses.
 *
 * Listing, catalogue search, saving and withdrawing are the administrator Wording screen's operations. The
 * service keeps every rule — `localization.overrides.manage`, the administered-layer and carried-locale
 * checks, identifier grammar, ICU validation, the transaction and the audit event — so the machine surface
 * refuses exactly what the screen refuses. Form-shape, identifier and pattern refusals become 422 problem
 * documents; a policy refusal propagates to the pipeline's problem-details boundary as the screen lets it.
 *
 * @since  2.0.0
 */
final readonly class WordingApiHandler implements RequestHandlerInterface
{
    /**
     * Bind the routes to the wording service and the shared problem factory.
     *
     * @param  MessageOverrideService         $overrides  Authorized, validated, audited wording writer.
     * @param  ProblemDetailsResponseFactory  $problems   Stable RFC 9457 response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageOverrideService $overrides,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Dispatch one wording route by method and path.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request past the capability pre-flight.
     *
     * @return  ResponseInterface  No-store JSON or a 422 problem document.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When the wording service refuses the capability.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = rtrim($request->getUri()->getPath(), '/');
        $context = ApiExecutionContext::fromRequest($request);
        try {
            if ($method === 'GET' && str_ends_with($path, '/catalogue')) {
                $query = self::query($request, ['locale', 'q', 'limit']);
                $locale = self::string($query, 'locale');

                return self::json([
                    'locale' => $locale,
                    'items' => $this->overrides->searchCatalogue(
                        $context,
                        $locale,
                        self::string($query, 'q'),
                        self::limit($query['limit'] ?? null),
                    ),
                ]);
            }
            if ($method === 'GET') {
                $query = self::query($request, ['layer', 'locale']);
                $layer = self::layer($query['layer'] ?? 'site');
                $locale = isset($query['locale']) ? self::string($query, 'locale') : null;

                return self::json([
                    'layer' => $layer->value,
                    'locale' => $locale,
                    'items' => array_map(
                        static fn (MessageOverrideRecord $record): array => $record->toArray(),
                        $this->overrides->overrides($context, $layer, $locale),
                    ),
                ]);
            }
            $body = self::body($request);
            if ($method === 'PUT') {
                self::keys($body, ['layer', 'locale', 'identifier', 'pattern']);

                return self::json($this->overrides->override(
                    $context,
                    self::layer($body['layer']),
                    self::string($body, 'locale'),
                    self::string($body, 'identifier'),
                    self::string($body, 'pattern'),
                )->toArray());
            }
            if ($method === 'POST' && str_ends_with($path, '/withdraw')) {
                self::keys($body, ['layer', 'locale', 'identifier']);

                return self::json(['withdrawn' => $this->overrides->withdraw(
                    $context,
                    self::layer($body['layer']),
                    self::string($body, 'locale'),
                    self::string($body, 'identifier'),
                )]);
            }

            throw new InvalidArgumentException('The wording operation is not supported.');
        } catch (InvalidArgumentException | MessageFormattingFailed | JsonException $refused) {
            return $this->problems->create(
                422,
                'Unprocessable Wording',
                $refused->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
    }

    /**
     * Read the query parameters and refuse any member outside the route's closed set.
     *
     * @param   ServerRequestInterface  $request  Request whose query is read.
     * @param   list<string>            $allowed  Accepted member names.
     *
     * @return  array<string, mixed>  Query parameters.
     *
     * @throws  InvalidArgumentException  When an unsupported member is present.
     *
     * @since   2.0.0
     */
    private static function query(ServerRequestInterface $request, array $allowed): array
    {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new InvalidArgumentException('The wording query contains unsupported parameters.');
        }

        return $query;
    }

    /**
     * Decode the JSON object body of one wording write.
     *
     * @param   ServerRequestInterface  $request  Write request.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @throws  JsonException  When the body is not JSON.
     * @throws  InvalidArgumentException  When the body is not a JSON object.
     *
     * @since   2.0.0
     */
    private static function body(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new InvalidArgumentException('Wording input must be a JSON object.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Refuse a body that carries a member outside, or omits one of, the operation's closed set.
     *
     * @param   array<string, mixed>  $body     Decoded body.
     * @param   list<string>          $members  Exact required member names.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the member set differs.
     *
     * @since   2.0.0
     */
    private static function keys(array $body, array $members): void
    {
        $present = array_keys($body);
        sort($present);
        sort($members);
        if ($present !== $members) {
            throw new InvalidArgumentException(sprintf('Wording input requires exactly: %s.', implode(', ', $members)));
        }
    }

    /**
     * Read one required non-empty string member.
     *
     * @param   array<string, mixed>  $values  Query or body members.
     * @param   string                $name    Member to read.
     *
     * @return  string  The value.
     *
     * @throws  InvalidArgumentException  When the member is absent, empty or not a string.
     *
     * @since   2.0.0
     */
    private static function string(array $values, string $name): string
    {
        $value = $values[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The wording %s is required.', $name));
        }

        return $value;
    }

    /**
     * Resolve an administered layer spelled by the caller; the service refuses any other layer again.
     *
     * @param   mixed  $value  `site` or `organization`.
     *
     * @return  MessageCatalogueLayer  The named layer.
     *
     * @throws  InvalidArgumentException  When the value names no administered layer.
     *
     * @since   2.0.0
     */
    private static function layer(mixed $value): MessageCatalogueLayer
    {
        $layer = is_string($value) ? MessageCatalogueLayer::tryFrom($value) : null;
        if ($layer !== MessageCatalogueLayer::Site && $layer !== MessageCatalogueLayer::Organization) {
            throw new InvalidArgumentException('An administered wording layer is required.');
        }

        return $layer;
    }

    /**
     * Read the optional catalogue search bound.
     *
     * @param   mixed  $value  Canonical decimal from one to two hundred, or null for the default of fifty.
     *
     * @return  int  Bounded limit.
     *
     * @throws  InvalidArgumentException  When the value is not canonical or out of range.
     *
     * @since   2.0.0
     */
    private static function limit(mixed $value): int
    {
        if ($value === null) {
            return 50;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > 200) {
            throw new InvalidArgumentException('The wording search limit must be between 1 and 200.');
        }

        return (int) $value;
    }

    /**
     * Answer one no-store JSON document.
     *
     * @param   array<string, mixed>  $document  Response body.
     *
     * @return  ResponseInterface  A 200 JSON response.
     *
     * @since   2.0.0
     */
    private static function json(array $document): ResponseInterface
    {
        return new JsonResponse($document, 200, ['Cache-Control' => 'no-store']);
    }
}
