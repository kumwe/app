<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Artifact;

use stdClass;

/**
 * Fail-closed lexical admission policy for persisted Studio JSON documents.
 *
 * Canonical Studio artifacts and recovery envelopes are data, never an HTML/CSS/script transport. This
 * walk runs before persistence and refuses executable schemes, markup, style/script-bearing member names,
 * and URLs outside an explicitly URL-shaped member. It never sanitizes or rewrites accepted values.
 *
 * @since  2.0.0
 */
final class StudioStoredDocumentPolicy
{
    /**
     * Prove that a decoded JSON value carries no persisted executable presentation material.
     *
     * @param   mixed        $value       Decoded JSON value.
     * @param   string|null  $memberName  Member that owns this value, when any.
     *
     * @return  void
     *
     * @throws  UnsafeStudioStoredDocument  When persistence would retain unsafe material.
     *
     * @since   2.0.0
     */
    public static function assertSafe(mixed $value, ?string $memberName = null): void
    {
        if (is_string($value)) {
            self::assertSafeString($value, $memberName);

            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertSafe($item, $memberName);
            }

            return;
        }
        if (!$value instanceof stdClass) {
            return;
        }
        foreach (get_object_vars($value) as $name => $item) {
            if (self::unsafeMember($name)) {
                throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::UnsafeMember);
            }
            self::assertSafe($item, $name);
        }
    }

    /**
     * Refuse executable, markup, CSS and out-of-schema URL strings.
     *
     * @param   string       $value       Candidate string.
     * @param   string|null  $memberName  Owning member, when any.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertSafeString(string $value, ?string $memberName): void
    {
        if (
            preg_match('/<\s*(?:!doctype|\/?[a-z][^>]*)>/iu', $value) === 1
            || preg_match('/(?:javascript|vbscript)\s*:/iu', $value) === 1
            || preg_match('/data\s*:\s*(?:text\/html|image\/svg\+xml)/iu', $value) === 1
            || preg_match('/(?:eval|function)\s*\(|=>/u', $value) === 1
        ) {
            throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::ExecutableContent);
        }
        if (
            $memberName !== null
            && preg_match('/(?:^|[-_])(?:style|css)(?:$|[-_])/iu', $memberName) === 1
            && preg_match('/(?:^|[;{])\s*--?[a-z-]+\s*:|[a-z-]+\s*:[^;{}]+;?/iu', $value) === 1
        ) {
            throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::StyleContent);
        }
        if (preg_match('#^(?:https?:)?//#iu', $value) !== 1) {
            return;
        }
        if ($memberName === null || preg_match('/(?:url|uri|href|src)$/iu', $memberName) !== 1) {
            throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::OutOfSchemaUrl);
        }
        if (preg_match('#^https://#iu', $value) !== 1) {
            throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::UnsafeUrl);
        }
        $parts = parse_url($value);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (
            !is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                && filter_var($host, FILTER_VALIDATE_IP) !== false
        ) {
            throw new UnsafeStudioStoredDocument(StudioStoredDocumentRejection::UnsafeUrl);
        }
    }

    /**
     * Recognize member names that would turn the artifact store into a presentation-code store.
     *
     * @param   string  $name  JSON object member.
     *
     * @return  bool  True when the member is never admitted.
     *
     * @since   2.0.0
     */
    private static function unsafeMember(string $name): bool
    {
        return in_array(strtolower($name), [
            'code', 'css', 'executable', 'html', 'markup', 'script', 'scripts', 'style', 'styles',
            'stylesheet', 'stylesheets',
        ], true);
    }
}
