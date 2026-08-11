<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the production component gallery that proves the current KIS contract.
 *
 * The gallery uses the same server-rendered Twig components and compiled assets as product screens, so
 * component browser tests exercise production behavior instead of a disconnected design mock. It is
 * read-only and sits behind the ordinary administrator boundary; no interface policy is authored here.
 *
 * @since  2.0.0
 */
final readonly class AdministratorInterfaceStandardHandler implements RequestHandlerInterface
{
    /**
     * Stable gallery concerns rendered as URL-addressable tabs.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const TABS = [
        'overview' => 'Overview',
        'collections' => 'Collections',
        'forms' => 'Forms and drawers',
        'safety' => 'Safety and states',
    ];

    /**
     * Bind the gallery to the ordinary administrator renderer and its recovery behavior.
     *
     * @param  AdministratorRenderer  $renderer  Renders the production KIS gallery template.
     *
     * @since  2.0.0
     */
    public function __construct(private AdministratorRenderer $renderer)
    {
    }

    /**
     * Render the selected gallery concern without accepting arbitrary template or component names.
     *
     * @param   ServerRequestInterface  $request  Authorized administrator request carrying an optional
     *          bounded `tab` query value.
     *
     * @return  ResponseInterface  No-store HTML containing representative KIS components and states.
     *
     * @throws  \InvalidArgumentException  When the administrator middleware did not attach a session.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $requested = is_string($query['tab'] ?? null) ? trim($query['tab']) : '';
        $active = array_key_exists($requested, self::TABS) ? $requested : 'overview';
        $tabs = [];
        foreach (self::TABS as $identifier => $label) {
            $tabs[] = [
                'id' => $identifier,
                'label' => $label,
                'href' => '/administrator/interface-standard?tab=' . rawurlencode($identifier),
            ];
        }

        return new HtmlResponse($this->renderer->render('interface-standard', [
            'csrf' => AdministratorRequest::session($request)->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'active_tab' => $active,
            'gallery_tabs' => $tabs,
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
