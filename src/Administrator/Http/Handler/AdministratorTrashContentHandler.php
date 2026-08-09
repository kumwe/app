<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Moves one content entry to the trash from the administrator content browser.
 *
 * Trashing is reversible: the row is marked rather than deleted, so this handler and
 * `AdministratorRestoreContentHandler` are the two halves of one undoable pair and nothing an author
 * wrote is lost. It is mounted on `POST /administrator/content/{id}/trash` behind the CSRF middleware
 * and the `content.delete` capability, renders nothing of its own, and always hands the operator back
 * to the content list.
 *
 * @since  2.0.0
 */
final readonly class AdministratorTrashContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the service that performs and audits the trashing.
     *
     * @param  ContentService  $content  Applies the trashing and records the audit event for it.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content)
    {
    }

    /**
     * Trash the entry named by the route and send the operator back to the content list.
     *
     * The posted `version` pins what the operator was looking at, so an entry someone else has edited
     * since is refused rather than trashed out from under them. The redirect targets the list rather
     * than the editor because the entry has just left the active listing the operator came from.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `version`, with `{id}` in the route.
     *
     * @return  ResponseInterface  A 303 redirect to `/administrator/content`.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier or `version` is not a positive integer.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `content.delete` is refused.
     * @throws  \Kumwe\CMS\Content\Application\ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\CMS\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $this->content->trash(
            AdministratorRequest::context($request),
            AdministratorRequest::routeId($request),
            AdministratorRequest::positiveInteger($form, 'version'),
        );

        return new RedirectResponse('/administrator/content', 303);
    }
}
