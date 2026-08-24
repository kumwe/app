<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use InvalidArgumentException;

/**
 * Canonical media policy evaluator replayed against Studio's language-neutral vector corpus.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaUploadPolicy
{
    /**
     * Capture one host upload policy.
     *
     * @param   list<string>  $acceptedMediaTypes  Closed accepted media-type set.
     * @param   int           $maximumBytes        Inclusive byte ceiling.
     * @param   bool          $resumable           Whether the transfer transport can resume.
     * @param   int|null      $chunkBytes          Chunk bound when resumable.
     *
     * @throws  InvalidArgumentException  When the policy cannot produce a canonical plan.
     *
     * @since   2.0.0
     */
    public function __construct(
        public array $acceptedMediaTypes,
        public int $maximumBytes,
        public bool $resumable,
        public ?int $chunkBytes = null,
    ) {
        if ($acceptedMediaTypes === [] || count($acceptedMediaTypes) > 100) {
            throw new InvalidArgumentException('A Studio media policy requires one to one hundred media types.');
        }
        foreach ($acceptedMediaTypes as $mediaType) {
            if (
                strlen($mediaType) > 120
                || preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $mediaType)
                    !== 1
            ) {
                throw new InvalidArgumentException('A Studio media policy contains an invalid media type.');
            }
        }
        if (count(array_unique($acceptedMediaTypes)) !== count($acceptedMediaTypes)) {
            throw new InvalidArgumentException('A Studio media policy contains a duplicate media type.');
        }
        new StudioMediaUploadPlan($maximumBytes, $resumable, $chunkBytes);
    }

    /**
     * Authorize a canonical request or return a stable non-disclosing media failure.
     *
     * @param   StudioMediaUploadRequest  $request  Validated upload request.
     *
     * @return  StudioMediaUploadPlan  Deterministic copy of this policy's plan.
     *
     * @throws  StudioMediaPolicyRejected  When type or size policy refuses the request.
     *
     * @since   2.0.0
     */
    public function authorize(StudioMediaUploadRequest $request): StudioMediaUploadPlan
    {
        if (!in_array($request->mediaType, $this->acceptedMediaTypes, true)) {
            throw new StudioMediaPolicyRejected('studio.media/upload-failed');
        }
        if ($request->byteSize > $this->maximumBytes) {
            throw new StudioMediaPolicyRejected('studio.media/upload-too-large');
        }

        return new StudioMediaUploadPlan($this->maximumBytes, $this->resumable, $this->chunkBytes);
    }
}
