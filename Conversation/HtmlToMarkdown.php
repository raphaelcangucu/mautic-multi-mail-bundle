<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * HTML-only mail into the markdown subset the app and the web already render. Layout is
 * lost on purpose: the original HTML stays in a private blob behind "Ver original".
 */
final class HtmlToMarkdown
{
    private const BLOCKS = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'center', 'address', 'figure', 'table', 'tbody', 'thead', 'tfoot', 'form', 'fieldset', 'dl', 'dd', 'dt', 'pre', 'hr'];
    private const SKIP = ['head', 'title', 'style', 'script', 'noscript', 'template', 'svg', 'math', 'iframe', 'object', 'embed', 'select', 'button', 'textarea', 'input', 'img', 'video', 'audio', 'picture', 'map'];

    public function convert(string $html): string
    {
        if (trim($html) === '') { return ''; }
        $document = QuoteCutter::document($html);
        $root = $document?->getElementsByTagName('body')->item(0) ?? $document?->documentElement;
        if ($root === null) { return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
        $markdown = $this->children($root, false);
        $markdown = preg_replace("/[ \t]+\n/u", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/(?<=\S)[ \t]{2,}(?=\S)/u', ' ', $markdown) ?? $markdown;
        $markdown = preg_replace("/\n[ \t]+(?=\S)(?![-0-9>])/u", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/u", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function children(\DOMNode $node, bool $pre): string
    {
        $out = '';
        foreach ($node->childNodes as $child) { $out .= $this->node($child, $pre); }

        return $out;
    }

    private function node(\DOMNode $node, bool $pre): string
    {
        if ($node instanceof \DOMText) {
            return $pre ? $node->textContent : (preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        }
        if (!$node instanceof \DOMElement) { return ''; }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SKIP, true)) { return ''; }

        return match ($tag) {
            'br' => "\n",
            'strong', 'b' => $this->wrap($this->children($node, $pre), '**'),
            'em', 'i' => $this->wrap($this->children($node, $pre), '*'),
            'a' => $this->link($node, $pre),
            'ul', 'ol' => "\n\n".$this->list($node, $tag === 'ol', $pre)."\n\n",
            'li' => "\n- ".trim($this->children($node, $pre))."\n",
            'blockquote' => "\n\n".implode("\n", array_map(static fn(string $line): string => rtrim('> '.$line), explode("\n", trim($this->children($node, $pre)))))."\n\n",
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => "\n\n".$this->wrap(trim($this->children($node, $pre)), '**')."\n\n",
            'tr' => "\n".implode(' | ', array_filter(array_map(fn(\DOMNode $cell): string => trim(preg_replace('/\s+/u', ' ', $this->children($cell, $pre)) ?? ''), iterator_to_array($node->childNodes)), static fn(string $cell): bool => $cell !== ''))."\n",
            'pre' => "\n\n".$this->children($node, true)."\n\n",
            default => in_array($tag, self::BLOCKS, true) ? "\n\n".$this->children($node, $pre)."\n\n" : $this->children($node, $pre),
        };
    }

    private function list(\DOMElement $list, bool $ordered, bool $pre): string
    {
        $items = [];
        $number = 1;
        foreach ($list->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower($child->tagName) !== 'li') { continue; }
            $text = trim(preg_replace('/\n{2,}/u', "\n", $this->children($child, $pre)) ?? '');
            if ($text === '') { continue; }
            $items[] = ($ordered ? ($number++).'. ' : '- ').str_replace("\n", "\n   ", $text);
        }

        return implode("\n", $items);
    }

    private function link(\DOMElement $node, bool $pre): string
    {
        $text = trim($this->children($node, $pre));
        $href = trim($node->getAttribute('href'));
        if (!preg_match('/^(?:https?:\/\/|mailto:)[^\s()<>]{1,2000}$/Di', $href)) { return $text; }
        if ($text === '' || $text === $href || 'mailto:'.$text === $href) { return $href; }

        return '['.str_replace(['[', ']'], ['(', ')'], $text).']('.$href.')';
    }

    private function wrap(string $text, string $marker): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') { return $text; }
        $leading = $text !== ltrim($text) ? ' ' : '';
        $trailing = $text !== rtrim($text) ? ' ' : '';

        return $leading.$marker.$trimmed.$marker.$trailing;
    }
}
