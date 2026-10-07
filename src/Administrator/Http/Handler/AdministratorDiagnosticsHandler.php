<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read-only operator screen over the same authority and budgets as REST, CLI and MCP.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDiagnosticsHandler implements RequestHandlerInterface
{
    /**
     * Bind the shared reader and standard administrator renderer.
     *
     * @param  OperatorDiagnostics    $diagnostics  Authorized bounded reader.
     * @param  AdministratorRenderer  $renderer     Existing administrator shell.
     *
     * @since  2.0.0
     */
    public function __construct(private OperatorDiagnostics $diagnostics, private AdministratorRenderer $renderer)
    {
    }

    /**
     * Render one selected diagnostic, with no caching of operational information.
     *
     * @param   ServerRequestInterface  $request  Authenticated administrator request.
     *
     * @return  ResponseInterface  Operator screen with bounded results and source availability.
     *
     * @throws  InvalidArgumentException  When the section is malformed.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $section = $request->getQueryParams()['section'] ?? 'queues';
        if (!is_string($section)) {
            throw new InvalidArgumentException('The diagnostic section must be a string.');
        }

        return new HtmlResponse($this->renderer->render('diagnostics', [
            'csrf' => AdministratorRequest::session($request)->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'sections' => OperatorDiagnostics::SECTIONS,
            'diagnostic' => $this->diagnostics->read(AdministratorRequest::context($request), $section),
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
