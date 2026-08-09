<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\CMS\Presentation\ThemeSurface;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies every button on the extensions screen: the lifecycle changes and the signing-key operations.
 *
 * One route, `POST /administrator/extensions/action`, backs both because they share a screen, a
 * capability and a refusal vocabulary. What makes this handler more than a dispatcher is that it turns
 * the refusals into problem documents rather than letting them reach the generic error page: an
 * operator who is throttled, who has not re-entered their password, or who lacks the theme capability
 * gets a machine-readable 429, 403 or 422 with the reason in `detail`, which is what the screen renders
 * beside the control that was pressed. Anything that succeeds ends in a redirect, so the browser never
 * holds a resubmittable extension mutation.
 *
 * @since  2.0.0
 */
final readonly class AdministratorExtensionActionHandler implements RequestHandlerInterface
{
    /**
     * Wire the action route to the extension registry and the signing-key trust store.
     *
     * @param  ExtensionManager  $extensions  Performs the activate, disable and uninstall lifecycle changes.
     * @param  TrustStore        $trust       Adds, rotates and revokes the keys extension packages are signed with.
     *
     * @since  2.0.0
     */
    public function __construct(private ExtensionManager $extensions, private TrustStore $trust)
    {
    }

    /**
     * Apply the extension or trust-key operation the form's `action` field names.
     *
     * The four `trust-*` actions manage signing keys and each returns as soon as it is applied;
     * everything else is a lifecycle change against the `identifier` field. A `surface` is only
     * meaningful for template extensions, and `current_password` is passed straight through to the
     * step-up boundary rather than being checked here. A refusal raised while an action is applied
     * becomes a JSON problem document — throttling a 429, a missing step-up or capability a 403, a
     * rejected argument a 422 — so the screen can show the reason inline; every success ends in a 303
     * back to the screen.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  A 303 back to the extensions screen, or a JSON problem document on refusal.
     *
     * @throws  InvalidArgumentException  When the request carries no execution context, or `action` is missing;
     *          the same failure raised while applying an action is answered with a 422 instead.
     * @throws  \DateMalformedStringException  When a trust key form's `expires_at` is not a readable date.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $context = AdministratorRequest::context($request);
        $action = AdministratorRequest::required($form, 'action');

        try {
            if ($action === 'trust-add') {
                $this->trust->add(
                    $context,
                    AdministratorRequest::required($form, 'key_id'),
                    AdministratorRequest::required($form, 'public_key_base64'),
                    ($form['vendor_namespace'] ?? '') === '' ? '*' : $form['vendor_namespace'],
                    ($form['extension_pattern'] ?? '') === '' ? '*' : $form['extension_pattern'],
                    new DateTimeImmutable(AdministratorRequest::required($form, 'expires_at')),
                );
                return new RedirectResponse('/administrator/extensions', 303);
            }
            if ($action === 'trust-revoke') {
                $this->trust->finalizeRotation(
                    $context,
                    AdministratorRequest::required($form, 'key_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
                return new RedirectResponse('/administrator/extensions', 303);
            }
            if ($action === 'trust-emergency-revoke') {
                $this->trust->emergencyRevoke(
                    $context,
                    AdministratorRequest::required($form, 'key_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
                return new RedirectResponse('/administrator/extensions', 303);
            }
            if ($action === 'trust-rotate') {
                $this->trust->rotate(
                    $context,
                    AdministratorRequest::required($form, 'old_key_id'),
                    AdministratorRequest::required($form, 'new_key_id'),
                    AdministratorRequest::required($form, 'public_key_base64'),
                    ($form['vendor_namespace'] ?? '') === '' ? '*' : $form['vendor_namespace'],
                    ($form['extension_pattern'] ?? '') === '' ? '*' : $form['extension_pattern'],
                    new DateTimeImmutable(AdministratorRequest::required($form, 'expires_at')),
                );
                return new RedirectResponse('/administrator/extensions', 303);
            }

            $identifier = AdministratorRequest::required($form, 'identifier');
            $surface = ThemeSurface::optional($form['surface'] ?? null);
            $stepUpCredential = $form['current_password'] ?? null;
            if ($stepUpCredential !== null && !is_string($stepUpCredential)) {
                throw new InvalidArgumentException('The current password must be a string.');
            }

            match ($action) {
                'activate' => $this->extensions->activate(
                    $identifier,
                    $context,
                    $surface,
                    $stepUpCredential,
                ),
                'disable' => $this->extensions->disable(
                    $identifier,
                    $context,
                    $stepUpCredential,
                ),
                'uninstall' => $this->extensions->uninstall(
                    $identifier,
                    $context,
                    $stepUpCredential,
                ),
                default => throw new InvalidArgumentException('The extension action is not supported.'),
            };
        } catch (AuthenticationThrottled $exception) {
            return new JsonResponse([
                'type' => 'urn:kumwe:problem:authentication-throttled',
                'title' => 'Too Many Authentication Attempts',
                'status' => 429,
                'detail' => $exception->getMessage(),
            ], 429, ['Cache-Control' => 'no-store', 'Retry-After' => '900']);
        } catch (StepUpAuthenticationRequired $exception) {
            return new JsonResponse([
                'type' => 'urn:kumwe:problem:step-up-required',
                'title' => 'Step-up Authentication Required',
                'status' => 403,
                'detail' => $exception->getMessage(),
            ], 403, ['Cache-Control' => 'no-store']);
        } catch (AuthorizationDenied | InsufficientCapability $exception) {
            return new JsonResponse([
                'type' => 'urn:kumwe:problem:insufficient-capability',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => $exception->getMessage(),
            ], 403, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'type' => 'urn:kumwe:problem:validation-failed',
                'title' => 'Unprocessable Extension Operation',
                'status' => 422,
                'detail' => $exception->getMessage(),
            ], 422, ['Cache-Control' => 'no-store']);
        }

        return new RedirectResponse('/administrator/extensions', 303);
    }
}
