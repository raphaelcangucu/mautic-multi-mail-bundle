<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * The markdown subset the Inbox composer shows: paragraphs, line breaks, bold, italic,
 * links, lists and quotes. Everything else is escaped text. No images, no raw HTML and no
 * rewritten links: the conversational channel never tracks the reader.
 */
final class MarkdownRenderer
{
    public function toHtml(string $markdown): string
    {
        $html = [];
        foreach ($this->blocks($markdown) as [$type, $lines]) {
            $html[] = match ($type) {
                'ul', 'ol' => '<'.$type.' style="margin:0 0 12px;padding-left:24px">'.implode('', array_map(fn(string $line): string => '<li>'.$this->inline($line).'</li>', $lines)).'</'.$type.'>',
                'quote' => '<blockquote style="margin:0 0 12px;padding-left:12px;border-left:3px solid #dadce0;color:#5f6368">'.implode('<br>', array_map(fn(string $line): string => $this->inline($line), $lines)).'</blockquote>',
                default => '<p style="margin:0 0 12px">'.implode('<br>', array_map(fn(string $line): string => $this->inline($line), $lines)).'</p>',
            };
        }

        return implode("\n", $html);
    }

    /** The text/plain alternative: markers removed, link targets spelled out. */
    public function toText(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = preg_replace_callback('/\[([^\]\n]{1,500})\]\(((?:https?:\/\/|mailto:)[^\s)]{1,2000})\)/', static function (array $match): string {
            return $match[1] === $match[2] ? $match[2] : $match[1].' ('.$match[2].')';
        }, $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '$2', $text) ?? $text;

        return trim($text);
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function blocks(string $markdown): array
    {
        $blocks = [];
        $current = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            $line = rtrim($line);
            if (trim($line) === '') { $current = null; continue; }
            if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $match)) { $type = 'ul'; $line = $match[1]; }
            elseif (preg_match('/^\s{0,3}\d{1,3}[.)]\s+(.*)$/', $line, $match)) { $type = 'ol'; $line = $match[1]; }
            elseif (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $match)) { $type = 'quote'; $line = $match[1]; }
            else { $type = 'p'; }
            if ($current === null || $blocks[$current][0] !== $type) {
                $blocks[] = [$type, []];
                $current = array_key_last($blocks);
            }
            $blocks[$current][1][] = $line;
        }

        return $blocks;
    }

    private function inline(string $text): string
    {
        $tokens = [];
        $hold = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;
            return "\0".(count($tokens) - 1)."\0";
        };
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $text = str_replace("\0", '', $text);
        // Links first, so emphasis markers inside URLs are never interpreted.
        $text = preg_replace_callback('/\[([^\]]{1,500})\]\(((?:https?:\/\/|mailto:)[^\s)]{1,2000})\)/', function (array $match) use ($hold, $escape): string {
            return $hold('<a href="'.$escape($match[2]).'" style="color:#1a73e8">'.$this->emphasis($escape($match[1])).'</a>');
        }, $text) ?? '';
        $text = preg_replace_callback('/\bhttps?:\/\/[^\s<>"\x00]{1,2000}/', static function (array $match) use ($hold, $escape): string {
            $url = rtrim($match[0], '.,;:!?)');
            return $hold('<a href="'.$escape($url).'" style="color:#1a73e8">'.$escape($url).'</a>').substr($match[0], strlen($url));
        }, $text) ?? '';
        $html = $this->emphasis($escape($text));

        return preg_replace_callback('/\x00(\d+)\x00/', static fn(array $match): string => $tokens[(int) $match[1]] ?? '', $html) ?? '';
    }

    private function emphasis(string $html): string
    {
        $html = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '<strong>$2</strong>', $html) ?? $html;

        return preg_replace('/(?<![\w*])([*_])(?=\S)(.+?)(?<=\S)\1(?![\w*])/u', '<em>$2</em>', $html) ?? $html;
    }
}
