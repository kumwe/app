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

final readonly class AdministratorExtensionActionHandler implements RequestHandlerInterface
{
    public function __construct(private ExtensionManager $extensions, private TrustStore $trust)
    {
    }

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
        } catch (AuthorizationDenied|InsufficientCapability $exception) {
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
