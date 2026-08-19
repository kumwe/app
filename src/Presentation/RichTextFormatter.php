<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation;

/**
 * Renders the restricted markup editors may store in a content body into HTML that is safe to print raw.
 *
 * Kumwe deliberately does not accept HTML from editors. A body is plain text with a small, closed set of
 * markers — `## ` headings, `- ` bullets, blank-line-separated paragraphs, `**bold**`, and
 * `[label](url)` links — and this formatter is the only translator from that dialect into markup. Every
 * character that is not part of a recognised marker is escaped, and a link whose target is not a
 * fragment, a root-relative path, or an `http`, `https` or `mailto` URL is emitted as escaped text
 * instead of an anchor. That is what lets `ContentPresenter` promise templates a `body_html` they can
 * print with Twig's `raw` filter without a sanitiser in between.
 *
 * @since  2.0.0
 */
final class RichTextFormatter
{
    /**
     * Renders one stored body into a block-level HTML fragment.
     *
     * Line endings are normalised first, so text authored on any platform produces the same blocks. A
     * blank line closes whatever paragraph or list is open, single newlines inside a paragraph become
     * `<br>`, and consecutive `- ` lines collapse into one `<ul>`.
     *
     * @param   string  $source  Stored body in Kumwe's restricted markup.
     *
     * @return  string  Newline-joined block elements, already escaped; empty when the body is blank.
     *
     * @since   2.0.0
     */
    public function format(string $source): string
    {
        $source = trim(str_replace(["\r\n", "\r"], "\n", $source));
        if ($source === '') {
            return '';
        }

        /** @var list<string> $blocks */
        $blocks = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var list<string> $list */
        $list = [];
        $flushParagraph = static function () use (&$blocks, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }
            $blocks[] = '<p>' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        };
        $flushList = static function () use (&$blocks, &$list): void {
            if ($list === []) {
                return;
            }
            $blocks[] = '<ul><li>' . implode('</li><li>', $list) . '</li></ul>';
            $list = [];
        };

        foreach (explode("\n", $source) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flushParagraph();
                $flushList();
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $flushParagraph();
                $flushList();
                $blocks[] = '<h2>' . $this->inline(substr($line, 3)) . '</h2>';
                continue;
            }
            if (str_starts_with($line, '- ')) {
                $flushParagraph();
                $list[] = $this->inline(substr($line, 2));
                continue;
            }
            $flushList();
            $paragraph[] = $this->inline($line);
        }
        $flushParagraph();
        $flushList();

        return implode("\n", $blocks);
    }

    /**
     * Renders the inline markers inside a single line of block content.
     *
     * The text between matches is escaped as it is consumed, so no unescaped input can reach the
     * output: a marker either produces the element it stands for or is escaped as literal text.
     *
     * @param   string  $source  One trimmed line, with its block marker already removed.
     *
     * @return  string  Escaped text with `<strong>` and `<a>` elements substituted in place.
     *
     * @since   2.0.0
     */
    private function inline(string $source): string
    {
        $output = '';
        $offset = 0;
        $pattern = '/\*\*([^*\n]+)\*\*|\[([^\]\n]+)\]\(([^)\s]+)\)/';
        while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $token = $match[0][0];
            $position = $match[0][1];
            $output .= $this->escape(substr($source, $offset, $position - $offset));
            if ($match[1][1] >= 0) {
                $output .= '<strong>' . $this->escape($match[1][0]) . '</strong>';
            } else {
                if (!isset($match[2], $match[3])) {
                    $output .= $this->escape($token);
                    $offset = $position + strlen($token);
                    continue;
                }
                $label = $match[2][0];
                $url = $match[3][0];
                $output .= $this->link($label, $url, $token);
            }
            $offset = $position + strlen($token);
        }

        return $output . $this->escape(substr($source, $offset));
    }

    /**
     * Renders one link marker as an anchor, or degrades it to escaped text when the target is unsafe.
     *
     * Rejecting a target never drops content: the reader still sees the marker the editor wrote, which
     * makes a blocked `javascript:` target visible rather than silently missing.
     *
     * @param   string  $label     Link text as the editor wrote it, escaped before it is emitted.
     * @param   string  $url       Link target, checked against the scheme allow-list before use.
     * @param   string  $fallback  Whole original marker, emitted escaped when the target is refused.
     *
     * @return  string  An `<a>` element, or the escaped marker when the target is not permitted.
     *
     * @since   2.0.0
     */
    private function link(string $label, string $url, string $fallback): string
    {
        if (!$this->isSafeUrl($url)) {
            return $this->escape($fallback);
        }

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $this->escape($label),
        );
    }

    /**
     * Decides whether a link target may be emitted as an `href`.
     *
     * Same-page fragments and root-relative paths are accepted, with `//` rejected so a protocol-relative
     * host cannot pose as a local path. Anything else must carry an `http`, `https` or `mailto` scheme,
     * which excludes `javascript:` and `data:` targets.
     *
     * @param   string  $url  Link target exactly as the editor wrote it.
     *
     * @return  bool  True when the target is safe to place in an `href` attribute.
     *
     * @since   2.0.0
     */
    private function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '#')) {
            return true;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    /**
     * Escapes literal text for both element content and attribute values.
     *
     * Quotes and invalid UTF-8 sequences are covered as well as the markup characters, so one helper
     * serves link targets, link labels, and body text alike.
     *
     * @param   string  $value  Text to render literally.
     *
     * @return  string  The text with HTML-significant characters replaced by entities.
     *
     * @since   2.0.0
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
