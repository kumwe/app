<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Http\Middleware;

use Kumwe\CMS\Localization\Application\ActiveLocale;
use Kumwe\CMS\Localization\Application\LocaleNegotiator;
use Kumwe\CMS\Localization\Application\TranslationScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the locale for one request and publishes it for everything downstream to render in.
 *
 * It publishes twice, deliberately, because two kinds of collaborator need it and they cannot both
 * be served the same way. A handler that already has the request reads the `kumwe.locale`
 * attribute, exactly as it reads `kumwe.request_id`. A Twig function or a presenter that receives
 * only its own arguments reads `ActiveLocale`, exactly as a log processor reads
 * `CorrelationContext`. Both are mechanisms this codebase already uses; neither is a new one.
 *
 * The holder is closed in a `finally`, so a long-lived process cannot carry one request's language
 * into the next one's output even when the request ends by throwing. It is piped early, before the
 * body limit and routing, because the error boundary renders text too and a problem document
 * produced for a rejected request should be in the caller's language rather than in the language
 * the last successful request happened to leave behind.
 *
 * @since  2.0.0
 */
final readonly class LocaleNegotiationMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the resolved locale tag is published under.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE = 'kumwe.locale';

    /**
     * Query parameter through which a caller states an explicit locale choice.
     *
     * A parameter rather than a header, because an explicit choice has to survive being copied into
     * a link, shared, and bookmarked; a header does not. An unrecognised value is ignored rather
     * than refused, so a stale bookmark renders in the negotiated language instead of failing.
     *
     * @var    string
     * @since  2.0.0
     */
    public const QUERY_PARAMETER = 'locale';

    /**
     * Bind the middleware to negotiation, the request-scoped holder and the site being served.
     *
     * @param  LocaleNegotiator  $negotiator  Resolver consulted for every request.
     * @param  ActiveLocale      $active      Holder opened for the duration of the request.
     * @param  string            $site        Identifier of the site this kernel serves, which scopes
     *         the administered override layers.
     *
     * @since  2.0.0
     */
    public function __construct(
        private LocaleNegotiator $negotiator,
        private ActiveLocale $active,
        private string $site,
    ) {
    }

    /**
     * Negotiate, publish, delegate, and close the holder however the request ends.
     *
     * @param   ServerRequestInterface   $request  Request whose explicit choice and `Accept-Language`
     *          are consulted.
     * @param   RequestHandlerInterface  $handler  Next handler, called with the locale attribute set.
     *
     * @return  ResponseInterface  The handler's response, unchanged.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = $request->getQueryParams();
        $explicit = $query[self::QUERY_PARAMETER] ?? null;
        $locale = $this->negotiator->negotiate(
            is_string($explicit) && $explicit !== '' ? $explicit : null,
            $request->getHeaderLine('Accept-Language'),
        );
        $this->active->begin($locale, new TranslationScope($this->site));

        try {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, $locale));
        } finally {
            $this->active->end();
        }
    }
}
