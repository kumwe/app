<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use InvalidArgumentException;
use stdClass;

/**
 * Canonical upload request descriptor validated before a host grant is allocated.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaUploadRequest
{
    /**
     * Capture one request that satisfies the pinned media-upload-session schema.
     *
     * @param   string       $filename   Display filename, never a storage path.
     * @param   string       $mediaType  Browser-declared media type used only for policy and later comparison.
     * @param   int          $byteSize   Exact byte count declared before transfer.
     * @param   string       $purpose    Qualified semantic use for the upload.
     * @param   string|null  $checksum   Optional SRI digest compared with received bytes.
     *
     * @throws  InvalidArgumentException  When any member is outside the canonical request shape.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $filename,
        public string $mediaType,
        public int $byteSize,
        public string $purpose,
        public ?string $checksum = null,
    ) {
        if (
            $filename === ''
            || mb_strlen($filename) > 255
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $filename) === 1
        ) {
            throw new InvalidArgumentException('The Studio upload filename is invalid.');
        }
        if (
            strlen($mediaType) > 120
            || preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $mediaType) !== 1
        ) {
            throw new InvalidArgumentException('The Studio upload media type is invalid.');
        }
        if ($byteSize < 1 || $byteSize > 1_099_511_627_776) {
            throw new InvalidArgumentException('The Studio upload byte size is invalid.');
        }
        if (
            strlen($purpose) > 160
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $purpose)
                !== 1
        ) {
            throw new InvalidArgumentException('The Studio upload purpose is invalid.');
        }
        if (
            $checksum !== null && preg_match(
                '/^(?:sha256-[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw048]=|sha384-[A-Za-z0-9+\/]{64}'
                . '|sha512-[A-Za-z0-9+\/]{85}[AQgw]==)$/D',
                $checksum,
            ) !== 1
        ) {
            throw new InvalidArgumentException('The Studio upload checksum is invalid.');
        }
    }

    /**
     * Decode the exact closed request object carried inside `{request}` by the HTTP adapter.
     *
     * @param   mixed  $document  Decoded JSON request descriptor.
     *
     * @return  self
     *
     * @throws  InvalidArgumentException  When the object has missing, unknown or mistyped members.
     *
     * @since   2.0.0
     */
    public static function fromDocument(mixed $document): self
    {
        if (!$document instanceof stdClass) {
            throw new InvalidArgumentException('The Studio upload request must be an object.');
        }
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);
        if (
            $members !== ['byteSize', 'filename', 'mediaType', 'purpose']
            && $members !== ['byteSize', 'checksum', 'filename', 'mediaType', 'purpose']
        ) {
            throw new InvalidArgumentException('The Studio upload request shape is invalid.');
        }
        $checksum = property_exists($document, 'checksum') ? $document->checksum : null;
        if (
            !is_string($document->filename)
            || !is_string($document->mediaType)
            || !is_int($document->byteSize)
            || !is_string($document->purpose)
            || ($checksum !== null && !is_string($checksum))
        ) {
            throw new InvalidArgumentException('The Studio upload request values are invalid.');
        }

        return new self(
            $document->filename,
            $document->mediaType,
            $document->byteSize,
            $document->purpose,
            $checksum,
        );
    }

    /**
     * Project this immutable value back into canonical JSON shape.
     *
     * @return  stdClass  Detached canonical request object.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        $document = (object) [
            'filename' => $this->filename,
            'mediaType' => $this->mediaType,
            'byteSize' => $this->byteSize,
            'purpose' => $this->purpose,
        ];
        if ($this->checksum !== null) {
            $document->checksum = $this->checksum;
        }

        return $document;
    }
}
