<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * The three body fields of the payload: `text` (new part, markdown), `quoted` (markdown) and
 * `html` (new part, raw, never sanitized here: the Inbox sanitizes before showing it).
 * Each is capped at 64 KiB; the full HTML lives in a private blob.
 */
final class InboundBody
{
    public const MAX_BYTES = 65536;

    public function __construct(private readonly QuoteCutter $cutter = new QuoteCutter(), private readonly HtmlToMarkdown $markdown = new HtmlToMarkdown()) {}

    /** @return array{text: string, quoted: string, html: string} */
    public function extract(InboundEmail $email): array
    {
        $interleaved = false;
        if (trim($email->text) !== '') {
            // text/plain is preferred: it is what the lead's client meant as the readable form.
            $cut = $this->cutter->cutText($email->text);
            [$text, $quoted, $interleaved] = [$cut['text'], $cut['quoted'], $cut['interleaved']];
        }
        $html = '';
        if (trim($email->html) !== '') {
            $cut = $interleaved ? ['html' => $email->html, 'quoted' => ''] : $this->cutter->cutHtml($email->html);
            $html = $cut['html'];
            if (!isset($text)) {
                $text = $this->markdown->convert($cut['html']);
                $quoted = $cut['quoted'] === '' ? '' : implode("\n", array_map(static fn(string $line): string => rtrim('> '.$line), explode("\n", $this->markdown->convert($cut['quoted']))));
                if ($text === '') { $text = $this->markdown->convert($email->html); $quoted = ''; }
            }
        }

        return ['text' => self::cap($text ?? ''), 'quoted' => self::cap($quoted ?? ''), 'html' => self::cap($html)];
    }

    public static function cap(string $value, int $bytes = self::MAX_BYTES): string
    {
        return strlen($value) <= $bytes ? $value : mb_strcut($value, 0, $bytes, 'UTF-8');
    }
}
