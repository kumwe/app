<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

/**
 * One rejected content-editor submission, carried back to the editor so nothing typed is lost.
 *
 * The create and update routes answer with a redirect when a save succeeds, so an editor that saves
 * cleanly never meets this class. When the save is refused the submission is the only surviving copy
 * of the operator's work — a long document may be an hour of typing — and the failure is described
 * here rather than raised as an error page: the write handler builds one of these from the body it
 * just tried to store, and the editor re-renders its own screen from it. Retention and the reason for
 * the refusal travel together, because a form redrawn without the message would look like a save that
 * silently did nothing.
 *
 * This is the content-side counterpart of the retained input the generated business surfaces pass to
 * `BusinessSurfaceService::form()`; both keep the submitted values in front of the operator, and
 * neither writes anything on the way through.
 *
 * @since  2.0.0
 */
final readonly class ContentEditorSubmission
{
    /**
     * Capture the refused body together with the reason the store would not take it.
     *
     * At most one of `$violations` and `$staleVersion` is populated: a body that broke the content
     * type's schema carries its violations, and a body that lost an optimistic-concurrency race
     * carries the version it was composed against. Both default to empty so a caller can retain the
     * input first and describe the failure afterwards.
     *
     * @param  string                $contentType   Content type the submission was authored against.
     * @param  string                $title         Title exactly as submitted, before trimming.
     * @param  string                $slug          URL slug exactly as submitted.
     * @param  array<string, mixed>  $values        Entry data mapped from the submitted fields.
     * @param  string                $publishAt     Submitted publication start, blank when unset.
     * @param  string                $unpublishAt   Submitted publication end, blank when unset.
     * @param  list<string>          $violations    Schema violations, each prefixed with its JSON path.
     * @param  ?int                  $staleVersion  Version the refused submission quoted, or null when
     *         the failure was not an optimistic-concurrency conflict.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $contentType,
        public string $title,
        public string $slug,
        public array $values,
        public string $publishAt = '',
        public string $unpublishAt = '',
        public array $violations = [],
        public ?int $staleVersion = null,
    ) {
    }

    /**
     * Read the parts of a submitted editor body that must survive a refused save.
     *
     * Building the submission from the same flattened form the write handler read keeps the create and
     * update routes from drifting apart on what "the submitted form" means. The mapped `$values` are
     * passed in because only the caller knows which of the two body shapes — schema-generated inputs
     * or the raw JSON `data` field — the submission actually used.
     *
     * @param   array<string, string>  $form         Flattened administrator form as the handler read it.
     * @param   array<string, mixed>   $values       Entry data the handler mapped from that body.
     * @param   string                 $contentType  Content type the values were mapped against.
     *
     * @return  self  Retained submission with no failure attached yet.
     *
     * @since   2.0.0
     */
    public static function fromForm(array $form, array $values, string $contentType): self
    {
        return new self(
            $contentType,
            $form['title'] ?? '',
            $form['slug'] ?? '',
            $values,
            $form['publish_at'] ?? '',
            $form['unpublish_at'] ?? '',
        );
    }

    /**
     * Attach the schema violations that refused this submission.
     *
     * @param   list<string>  $violations  Messages the content-type schema produced, in validator order.
     *
     * @return  self  The same retained submission, now describing a validation failure.
     *
     * @since   2.0.0
     */
    public function rejectedBy(array $violations): self
    {
        return new self(
            $this->contentType,
            $this->title,
            $this->slug,
            $this->values,
            $this->publishAt,
            $this->unpublishAt,
            $violations,
            $this->staleVersion,
        );
    }

    /**
     * Attach the version this submission quoted after another writer moved the entry on first.
     *
     * @param   int  $version  Version the operator composed the refused submission against.
     *
     * @return  self  The same retained submission, now describing a version conflict.
     *
     * @since   2.0.0
     */
    public function conflictedAt(int $version): self
    {
        return new self(
            $this->contentType,
            $this->title,
            $this->slug,
            $this->values,
            $this->publishAt,
            $this->unpublishAt,
            $this->violations,
            $version,
        );
    }
}
