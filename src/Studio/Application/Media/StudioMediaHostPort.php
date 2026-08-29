<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\MediaPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Exact argument binding for all seven canonical Studio media operations.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaHostPort implements MediaPortInterface
{
    /**
     * Compose the App-owned media use case for direct Producer invocation.
     *
     * @param  StudioMediaOperations                 $media      Complete media use case.
     * @param  StudioProducerRequestAuthority|null  $authority  Authorized request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMediaOperations $media,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped media port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->media, $authority);
    }

    /**
     * Cancel one active upload inside Producer's host-atomic mutation boundary.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical empty acknowledgement.
     *
     * @since   2.0.0
     */
    public function abortUpload(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): null => $this->media->abortUpload(
            $this->requestAuthority()->context(),
            $this->requestAuthority()->snapshot(),
            self::wrappedString($arguments, 'uploadId'),
        ));
    }

    /**
     * Authorize one upload; the mutation boundary removes its live capability before replay storage.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical live upload grant.
     *
     * @since   2.0.0
     */
    public function authorizeUpload(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        $wrapper = self::wrapper($arguments, 'request');
        try {
            $descriptor = StudioMediaUploadRequest::fromDocument($wrapper->request);
        } catch (InvalidArgumentException) {
            StudioProducerError::refuse('validation-failed', 'studio.media/upload-failed');
        }

        return $this->result(fn (): stdClass => $this->media->authorizeUpload(
            $this->requestAuthority()->context(),
            $this->requestAuthority()->snapshot(),
            $descriptor,
        ));
    }

    /**
     * Verify and accept one completed upload.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical accepted asset identity.
     *
     * @since   2.0.0
     */
    public function completeUpload(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): stdClass => $this->media->completeUpload(
            $this->requestAuthority()->context(),
            $this->requestAuthority()->snapshot(),
            self::wrappedString($arguments, 'uploadId'),
        ));
    }

    /**
     * Resolve one media asset visible to this trusted App request.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical asset or explicit null.
     *
     * @since   2.0.0
     */
    public function get(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): ?stdClass => $this->media->get(
            $this->requestAuthority()->context(),
            self::wrappedString($arguments, 'assetId'),
        ));
    }

    /**
     * Harden and import one external media candidate.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical accepted asset identity.
     *
     * @since   2.0.0
     */
    public function importExternal(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): stdClass => $this->media->importExternal(
            $this->requestAuthority()->context(),
            self::wrappedString($arguments, 'url', stable: false),
        ));
    }

    /**
     * Return one bounded canonical media page.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical media page.
     *
     * @since   2.0.0
     */
    public function list(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): stdClass => $this->media->list(
            $this->requestAuthority()->context(),
            self::wrappedObject($arguments, 'query'),
        ));
    }

    /**
     * Poll one previously accepted asset.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical accepted asset state.
     *
     * @since   2.0.0
     */
    public function uploadStatus(mixed $arguments, RequestContext $context): HostResult
    {
        unset($context);
        return $this->result(fn (): stdClass => $this->media->uploadStatus(
            $this->requestAuthority()->context(),
            self::wrappedString($arguments, 'assetId'),
        ));
    }

    /**
     * Require an exact one-member operation wrapper.
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }

        return $value;
    }

    /**
     * Invoke one media use case and translate only its delivery-safe App refusal into Producer's error type.
     *
     * @param   callable(): mixed  $operation  Media operation producing one canonical JSON value.
     *
     * @return  HostResult  Canonical Producer success.
     *
     * @since   2.0.0
     */
    private function result(callable $operation): HostResult
    {
        try {
            return new HostResult($operation());
        } catch (AuthorizationDenied) {
            StudioProducerError::refuse('forbidden', 'studio.media/permission-refused');
        } catch (StudioMediaPortRejected $failure) {
            StudioProducerError::refuse(
                $failure->category,
                $failure->failureCode,
                commitsState: $failure->commitsState,
            );
        }
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio media port requires request authority.');
    }
}
