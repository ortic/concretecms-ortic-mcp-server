<?php

namespace Ortic\McpServer\Mcp\Support;

final class HtmlToText
{
    /**
     * @param bool $withLayout keep the theme header, navigation and footer
     */
    public static function extractPageContent(string $html, bool $withLayout = false): string
    {
        if ($html === '') {
            return '';
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $page = "//*[contains(concat(' ', normalize-space(@class), ' '), ' ccm-page ')]";
        $nodes = $withLayout ? null : $xpath->query($page . '//main');
        if (!$nodes || $nodes->length === 0) {
            $nodes = $xpath->query($page);
            if (!$withLayout && $nodes && $nodes->length > 0) {
                // No <main>: drop the usual layout regions of the theme.
                foreach (iterator_to_array($xpath->query('.//header | .//nav | .//footer', $nodes->item(0))) as $layout) {
                    if ($layout->parentNode) {
                        $layout->parentNode->removeChild($layout);
                    }
                }
            }
        }
        $node = $nodes && $nodes->length > 0 ? $nodes->item(0) : $dom->getElementsByTagName('body')->item(0);
        if (!$node) {
            return $html;
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return $inner;
    }

    public static function convert(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#<h([1-6])\b[^>]*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|section|article|header|footer|h[1-6]|ul|ol|table|tr|blockquote|nav|aside|main|figure)>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</t[dh]>#i', "\t", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
