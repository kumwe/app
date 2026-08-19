<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Undoes a trashing, bringing one content entry back into the administrator content browser.
 *
 * The other half of `AdministratorTrashContentHandler`: trashing only marks the row, so restoring is
 * a plain undo that returns the entry with its body, workflow state and revision trail intact. It is
 * mounted on `POST /administrator/content/{id}/restore` behind the CSRF middleware and the
 * `content.restore` capability, renders nothing of its own, and always hands the operator back to the
 * content list.
 *
 * @since  2.0.0
 */
final readonly class AdministratorRestoreContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen action to the service that performs and audits the restore.
     *
     * @param  ContentService  $content  Applies the restore and records the audit event for it.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content)
    {
    }

    /**
     * Restore the entry named by the route and send the operator back to the content list.
     *
     * The posted `version` is the one the operator was looking at, so an entry another editor has
     * moved on is refused instead of being quietly revived. Restoring an entry that is already live
     * is accepted as a no-op, which makes a repeated submission harmless.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `version`, with `{id}` in the route.
     *
     * @return  ResponseInterface  A 303 redirect to `/administrator/content`.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier or `version` is not a positive integer.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.restore` is refused.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $this->content->restore(
            AdministratorRequest::context($request),
            AdministratorRequest::routeId($request),
            AdministratorRequest::positiveInteger($form, 'version'),
        );

        return new RedirectResponse('/administrator/content', 303);
    }
}
