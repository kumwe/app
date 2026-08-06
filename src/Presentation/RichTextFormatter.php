<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

final class RichTextFormatter
{
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
