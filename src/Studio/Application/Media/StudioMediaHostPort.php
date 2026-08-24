<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Kumwe\App\Studio\Application\Host\StudioHostOutcome;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use stdClass;

/**
 * Exact HTTP-adapter argument binding for all seven canonical Studio media operations.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaHostPort
{
    /**
     * Compose the media use case with optional durable mutation replay.
     *
     * @param  StudioMediaOperations           $media        Complete media use case.
     * @param  StudioMediaMutationIdempotency  $idempotency  Durable supplied-key replay boundary.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMediaOperations $media,
        private StudioMediaMutationIdempotency $idempotency,
    ) {
    }

    /**
     * Decode the exact `{request|uploadId|assetId|url|query}` wrapper and invoke its operation.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostRequest          $request   Validated host envelope.
     * @param   StudioHostSessionSnapshot  $snapshot  Current authority snapshot.
     *
     * @return  StudioHostOutcome  Canonical host result or safe host error.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostOutcome {
        try {
            $this->permission($request, $snapshot);
            $value = match ($request->operationId) {
                'studio.operation/media.abort-upload' => $this->mutation(
                    $request,
                    $snapshot,
                    fn (): null => $this->media->abortUpload(
                        $context,
                        $snapshot,
                        self::wrappedString($request->arguments, 'uploadId'),
                    ),
                ),
                'studio.operation/media.authorize-upload' => $this->grantMutation(
                    $request,
                    $snapshot,
                    function () use ($context, $request, $snapshot): stdClass {
                        $wrapper = self::wrapper($request->arguments, 'request');
                        try {
                            $descriptor = StudioMediaUploadRequest::fromDocument($wrapper->request);
                        } catch (InvalidArgumentException) {
                            throw new StudioMediaPortRejected(
                                'validation-failed',
                                'studio.media/upload-failed',
                            );
                        }

                        return $this->media->authorizeUpload($context, $snapshot, $descriptor);
                    },
                    fn (stdClass $stored): stdClass => $this->media->replayUploadGrant(
                        $context,
                        $snapshot,
                        $stored,
                    ),
                ),
                'studio.operation/media.complete-upload' => $this->mutation(
                    $request,
                    $snapshot,
                    fn (): stdClass => $this->media->completeUpload(
                        $context,
                        $snapshot,
                        self::wrappedString($request->arguments, 'uploadId'),
                    ),
                ),
                'studio.operation/media.get' => $this->media->get(
                    $context,
                    self::wrappedString($request->arguments, 'assetId'),
                ),
                'studio.operation/media.import-external' => $this->mutation(
                    $request,
                    $snapshot,
                    fn (): stdClass => $this->media->importExternal(
                        $context,
                        self::wrappedString($request->arguments, 'url', stable: false),
                    ),
                ),
                'studio.operation/media.list' => $this->media->list(
                    $context,
                    self::wrappedObject($request->arguments, 'query'),
                ),
                'studio.operation/media.upload-status' => $this->media->uploadStatus(
                    $context,
                    self::wrappedString($request->arguments, 'assetId'),
                ),
                default => throw new StudioMediaPortRejected(
                    'incompatible',
                    'studio.host/operation-unavailable',
                ),
            };

            return self::result($value);
        } catch (AuthorizationDenied) {
            return StudioHostDispatcher::refusal(
                'forbidden',
                'studio.media/permission-refused',
            );
        } catch (StudioMediaPortRejected $failure) {
            return StudioHostDispatcher::refusal($failure->category, $failure->failureCode);
        }
    }

    /**
     * Replay a supplied mutation key under the exact current Studio scope.
     *
     * @param   StudioHostRequest          $request    Canonical envelope.
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted scope.
     * @param   callable(): mixed          $operation  Mutation body.
     *
     * @return  mixed  Fresh or replayed canonical value.
     *
     * @since   2.0.0
     */
    private function mutation(
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
        callable $operation,
    ): mixed {
        return $this->idempotency->run($request, $snapshot->session->actorId, $operation);
    }

    /**
     * Replay upload authorization through a ledger projection that never stores its live capability.
     *
     * @param   StudioHostRequest             $request    Canonical envelope.
     * @param   StudioHostSessionSnapshot     $snapshot   Trusted scope.
     * @param   callable(): stdClass          $operation  Fresh authorization body.
     * @param   callable(stdClass): stdClass  $restore    Restores only the verified grant token on replay.
     *
     * @return  stdClass  Fresh or safely restored canonical grant.
     *
     * @since   2.0.0
     */
    private function grantMutation(
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
        callable $operation,
        callable $restore,
    ): stdClass {
        $result = $this->idempotency->runGrant(
            $request,
            $snapshot->session->actorId,
            $operation,
            $restore,
        );
        if (!$result instanceof stdClass) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }

        return $result;
    }

    /**
     * Require the session's read permission and upload permission for mutations.
     *
     * @param   StudioHostRequest          $request   Canonical envelope.
     * @param   StudioHostSessionSnapshot  $snapshot  Current authority snapshot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function permission(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): void
    {
        if (!in_array('studio.permission/read', $snapshot->permissions, true)) {
            throw new StudioMediaPortRejected('forbidden', 'studio.media/permission-refused');
        }
        if (
            in_array($request->operationId, [
                'studio.operation/media.abort-upload',
                'studio.operation/media.authorize-upload',
                'studio.operation/media.complete-upload',
                'studio.operation/media.import-external',
            ], true)
            && !in_array('studio.permission/upload-media', $snapshot->permissions, true)
        ) {
            throw new StudioMediaPortRejected('forbidden', 'studio.media/permission-refused');
        }
    }

    /**
     * Require an exact one-member HTTP adapter wrapper.
     *
     * @param   mixed   $arguments  Decoded operation arguments.
     * @param   string  $member     Required member name.
     *
     * @return  stdClass  Exact wrapper.
     *
     * @since   2.0.0
     */
    private static function wrapper(mixed $arguments, string $member): stdClass
    {
        if (!$arguments instanceof stdClass || array_keys(get_object_vars($arguments)) !== [$member]) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.host/invalid-arguments');
        }

        return $arguments;
    }

    /**
     * Require an exact wrapper whose value is an object.
     *
     * @param   mixed   $arguments  Decoded operation arguments.
     * @param   string  $member     Required member name.
     *
     * @return  stdClass  Wrapped object.
     *
     * @since   2.0.0
     */
    private static function wrappedObject(mixed $arguments, string $member): stdClass
    {
        $wrapper = self::wrapper($arguments, $member);
        if (!$wrapper->{$member} instanceof stdClass) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.host/invalid-arguments');
        }

        return $wrapper->{$member};
    }

    /**
     * Require an exact wrapper whose value is a bounded string.
     *
     * @param   mixed   $arguments  Decoded operation arguments.
     * @param   string  $member     Required member name.
     * @param   bool    $stable     Whether to apply stable-identity syntax.
     *
     * @return  string  Wrapped string.
     *
     * @since   2.0.0
     */
    private static function wrappedString(mixed $arguments, string $member, bool $stable = true): string
    {
        $wrapper = self::wrapper($arguments, $member);
        $value = $wrapper->{$member};
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > ($stable ? 240 : 2048)
            || ($stable && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value) !== 1)
        ) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.host/invalid-arguments');
        }

        return $value;
    }

    /**
     * Wrap one canonical JSON value in a host result.
     *
     * @param   mixed  $value  Canonical operation value.
     *
     * @return  StudioHostOutcome  Successful host result.
     *
     * @since   2.0.0
     */
    private static function result(mixed $value): StudioHostOutcome
    {
        return new StudioHostOutcome(200, (object) ['value' => $value]);
    }
}
