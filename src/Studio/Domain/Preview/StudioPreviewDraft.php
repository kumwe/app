<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * Immutable canonical unpublished Blueprint selected for one preview attempt.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewDraft
{
    /**
     * Canonical bytes retained independently from caller-owned object identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $canonical;

    /**
     * Capture a canonical Blueprint and prove its routing identity.
     *
     * @param   string    $siteIdentifier  Trusted owning site.
     * @param   stdClass  $document        Schema-admitted Blueprint object.
     *
     * @throws  InvalidArgumentException  When required Blueprint identity members disagree.
     * @throws  \Kumwe\App\Studio\Domain\Contract\CanonicalJsonRejected  When bytes are not canonical JSON.
     *
     * @since   2.0.0
     */
    public function __construct(public string $siteIdentifier, stdClass $document)
    {
        if (
            $siteIdentifier === ''
            || ($document->kind ?? null) !== 'blueprint'
            || !is_string($document->id ?? null)
            || !is_string($document->revision ?? null)
            || !is_array($document->roots ?? null)
        ) {
            throw new InvalidArgumentException('The Studio preview draft identity is invalid.');
        }
        $this->canonical = CanonicalJson::stringify($document);
    }

    /**
     * Return a fresh object so rendering can never mutate the retained identity.
     *
     * @return  stdClass  Canonically equivalent Blueprint document.
     *
     * @throws  RuntimeException  If impossible in-memory corruption made the bytes unreadable.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        try {
            $document = json_decode($this->canonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The canonical Studio preview draft is unreadable.', 0, $exception);
        }
        if (!$document instanceof stdClass) {
            throw new RuntimeException('The canonical Studio preview draft is not an object.');
        }

        return $document;
    }

    /**
     * Return the stable Blueprint identifier.
     *
     * @return  string  Canonical artifact identity.
     *
     * @since   2.0.0
     */
    public function artifactId(): string
    {
        $document = $this->document();
        $artifactId = $document->id ?? null;
        if (!is_string($artifactId)) {
            throw new RuntimeException('The canonical Studio preview draft lost its artifact identity.');
        }

        return $artifactId;
    }

    /**
     * Return the immutable draft revision.
     *
     * @return  string  Canonical revision.
     *
     * @since   2.0.0
     */
    public function revision(): string
    {
        $document = $this->document();
        $revision = $document->revision ?? null;
        if (!is_string($revision)) {
            throw new RuntimeException('The canonical Studio preview draft lost its revision identity.');
        }

        return $revision;
    }

    /**
     * Digest the exact canonical UTF-8 representation.
     *
     * @return  string  Lowercase SHA-256 digest.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return hash('sha256', $this->canonical);
    }

    /**
     * Expose the canonical bytes to persistence and conformance tests without rewriting them.
     *
     * @return  string  Canonical Blueprint JSON.
     *
     * @since   2.0.0
     */
    public function canonical(): string
    {
        return $this->canonical;
    }
}
