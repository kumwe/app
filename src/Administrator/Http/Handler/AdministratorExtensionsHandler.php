<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves the extensions screen and installs the packages uploaded through it.
 *
 * Installed extensions and the signing keys that vouch for them are listed together, because deciding
 * whether a package may be installed is a question about both. The upload path is where the care is:
 * the archive is staged under an unguessable name inside a private directory, installed, and deleted
 * again whichever way the install went, so a rejected package never lingers on disk for a later request
 * to find. A failure re-renders this same screen with the reason inline instead of redirecting, which
 * keeps the operator's context — the sibling `AdministratorExtensionActionHandler` owns everything an
 * installed extension can then be told to do.
 *
 * @since  2.0.0
 */
final readonly class AdministratorExtensionsHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen to the registry, the trust store, the renderer and the upload staging area.
     *
     * @param  ExtensionManager       $extensions          Lists installed extensions and installs uploaded ones.
     * @param  TrustStore             $trust               Supplies the signing keys the screen lists.
     * @param  AdministratorRenderer  $renderer            Renders the `extensions` template.
     * @param  string                 $temporaryDirectory  Private directory uploaded archives are staged in; it
     *         is created mode 0700 when it does not yet exist.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionManager $extensions,
        private TrustStore $trust,
        private AdministratorRenderer $renderer,
        private string $temporaryDirectory,
    ) {
    }

    /**
     * Render the extensions screen, or install the package a `POST` uploads.
     *
     * Blank `key_id` and `signature` fields become null rather than empty strings, so an unsigned
     * package is offered to the trust rules as unsigned instead of as one carrying an empty signature.
     * Any install failure is caught and re-rendered as a 422 with the message beside the form, and the
     * staged archive is removed either way; a successful install redirects so a refresh cannot repeat it.
     *
     * @param   ServerRequestInterface  $request  Administrator request; `GET` lists, `POST` installs an upload.
     *
     * @return  ResponseInterface  The rendered screen — 200 listing, 422 after a refused install — or a 303.
     *
     * @throws  InvalidArgumentException  When a `POST` carries no successfully uploaded `package` file, or the
     *          route was mounted without administrator session middleware.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'GET') {
            return new HtmlResponse($this->renderer->render('extensions', [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'extensions' => $this->extensions->installed(AdministratorRequest::context($request)),
                'trust_keys' => $this->trust->keys(AdministratorRequest::context($request)),
            ]), 200, ['Cache-Control' => 'no-store']);
        }

        $upload = $request->getUploadedFiles()['package'] ?? null;

        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('A successfully uploaded extension ZIP is required.');
        }

        if (!is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0700, true);
        }

        $temporary = $this->temporaryDirectory . '/extension-' . bin2hex(random_bytes(16)) . '.zip';
        $form = AdministratorRequest::form($request);

        try {
            $upload->moveTo($temporary);
            $this->extensions->install(
                $temporary,
                AdministratorRequest::context($request),
                ($form['key_id'] ?? '') === '' ? null : $form['key_id'],
                ($form['signature'] ?? '') === '' ? null : $form['signature'],
            );
        } catch (Throwable $exception) {
            return new HtmlResponse($this->renderer->render('extensions', [
                'csrf' => $session->csrfToken,
                'capabilities' => AdministratorRequest::capabilityMap($request),
                'extensions' => $this->extensions->installed(AdministratorRequest::context($request)),
                'trust_keys' => $this->trust->keys(AdministratorRequest::context($request)),
                'error' => $exception->getMessage(),
            ]), 422, ['Cache-Control' => 'no-store']);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new RedirectResponse('/administrator/extensions', 303);
    }
}
