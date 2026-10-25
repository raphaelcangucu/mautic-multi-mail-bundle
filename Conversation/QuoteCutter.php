<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * Separates what the lead wrote from what the client quoted. The quoted part is kept (the
 * card shows it behind "•••"), never thrown away, so a wrong cut is visible, not lossy.
 *
 * Order: our sentinel; then the attribution and header blocks of Gmail, Outlook, Apple
 * Mail and Thunderbird in Portuguese and English; then `>` lines; then the `-- ` signature.
 * A reply written between or below quoted lines keeps everything as text.
 */
final class QuoteCutter
{
    private const ATTRIBUTION_START = '/^\s*(?:On|Em|El|Le|Am|Op|Il|Na|No|Às|As)\b/iu';
    private const ATTRIBUTION = '/^\s*(?:On|Em|El|Le|Am|Op|Il|Na|No|Às|As)\b.{0,400}?(?:wrote|escreveu|escribió|a écrit|schrieb|schreef|ha scritto)\s*:?\s*$/iu';
    private const ORIGINAL = '/^\s*-{2,}\s*(?:Original Message|Mensagem original|Mensaje original|Forwarded message|Mensagem encaminhada)\s*-{2,}\s*$/iu';
    private const HEADER_FROM = '/^\s*\**(?:From|De)\s*:\**\s*\S/iu';
    private const HEADER_DATE = '/^\s*\**(?:Sent|Date|Enviado|Enviada|Enviado em|Data)\s*:\**\s*\S/iu';
    private const SEPARATOR = '/^\s*_{10,}\s*$/';
    private const SIGNATURE = '/^--\s?$/';
    private const MOBILE = '/^\s*(?:Sent from my|Enviado do meu|Enviado de meu|Enviado do Outlook|Get Outlook for|Obter o Outlook para|Baixe o Outlook para)\b/iu';
    private const MAX_DOM_BYTES = 4194304;

    /** @return array{text: string, quoted: string, interleaved: bool} */
    public function cutText(string $text): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $cut = $this->quoteStart($lines);
        $interleaved = $cut !== null && $this->interleaved($lines, $cut);
        if ($interleaved) {
            // Inline or bottom-posted answer: keep it whole; only a trailing quote block moves.
            $last = $cut;
            foreach ($lines as $index => $line) {
                if ($index >= $cut && trim($line) !== '' && !$this->quoted($line)) { $last = $index; }
            }
            $cut = $last + 1;
        }
        $new = $cut === null ? $lines : array_slice($lines, 0, $cut);
        $quoted = $cut === null ? [] : array_slice($lines, $cut);
        $signature = $this->signatureStart($new);
        if ($signature !== null) {
            $quoted = array_merge(array_slice($new, $signature), $quoted === [] ? [] : [''], $quoted);
            $new = array_slice($new, 0, $signature);
        }
        $newText = trim(implode("\n", $new));
        if ($newText === '') {
            // Nothing above the quote: show the whole message rather than an empty card.
            return ['text' => trim(implode("\n", $lines)), 'quoted' => '', 'interleaved' => false];
        }

        return ['text' => $newText, 'quoted' => self::asQuote(trim(implode("\n", $quoted))), 'interleaved' => $interleaved];
    }

    /** @return array{html: string, quoted: string} body inner HTML of the new part and of the quoted part */
    public function cutHtml(string $html): array
    {
        if (trim($html) === '' || strlen($html) > self::MAX_DOM_BYTES) { return ['html' => $html, 'quoted' => '']; }
        $document = self::document($html);
        $body = $document?->getElementsByTagName('body')->item(0);
        if ($document === null || $body === null) { return ['html' => $html, 'quoted' => '']; }
        $xpath = new \DOMXPath($document);
        $class = static fn(string $name): string => '//*[contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")]';
        $candidates = [];
        foreach ($xpath->query('//text()[contains(., "'.OutboundEmailBuilder::SENTINEL.'")]') ?: [] as $node) {
            $element = $node->parentNode;
            for ($ancestor = $element; $ancestor !== null && $ancestor !== $body; $ancestor = $ancestor->parentNode) {
                if ($ancestor instanceof \DOMElement && strtolower($ancestor->tagName) === 'blockquote') { $element = $ancestor; }
            }
            if ($element instanceof \DOMElement && $element !== $body) { $candidates[] = $element; }
        }
        foreach ([$class('gmail_quote'), '//*[@id="divRplyFwdMsg"]', '//*[@id="appendonsend"]', $class('moz-cite-prefix'),
            '//blockquote[@type="cite"]', $class('yahoo_quoted'), '//*[@id="mail-editor-reference-message-container"]',
            '//div[contains(@style, "border-top") and (starts-with(normalize-space(.), "From:") or starts-with(normalize-space(.), "De:"))]'] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) { if ($node !== $body) { $candidates[] = $node; } }
        }
        if ($candidates === []) { return ['html' => self::inner($body), 'quoted' => '']; }
        $order = [];
        $index = 0;
        foreach ($xpath->query('//body//*') ?: [] as $node) { $order[spl_object_id($node)] = $index++; }
        usort($candidates, fn(\DOMNode $a, \DOMNode $b): int => ($order[spl_object_id($a)] ?? PHP_INT_MAX) <=> ($order[spl_object_id($b)] ?? PHP_INT_MAX));
        $start = $candidates[0];
        // Apple Mail and others write "On … wrote:" in the element right before the quote.
        for ($previous = $start->previousSibling; $previous !== null; $previous = $previous->previousSibling) {
            if ($previous instanceof \DOMText && trim($previous->textContent) === '') { continue; }
            if ($previous instanceof \DOMElement && strtolower($previous->tagName) === 'br') { continue; }
            if (preg_match(self::ATTRIBUTION, trim(preg_replace('/\s+/u', ' ', $previous->textContent) ?? ''))) { $start = $previous; }
            break;
        }
        $removed = [];
        for ($node = $start; $node !== null && $node !== $body; $node = $node->parentNode) {
            $sibling = $node === $start ? $node : $node->nextSibling;
            while ($sibling !== null) {
                $next = $sibling->nextSibling;
                $removed[] = $sibling;
                $sibling = $next;
            }
        }
        $quoted = '';
        foreach ($removed as $node) {
            $quoted .= $document->saveHTML($node);
            $node->parentNode?->removeChild($node);
        }
        $new = self::inner($body);
        if (trim(strip_tags($new)) === '') { return ['html' => $html, 'quoted' => '']; }

        return ['html' => $new, 'quoted' => $quoted];
    }

    /** UTF-8 HTML into a DOM without network access or entity expansion. */
    public static function document(string $html): ?\DOMDocument
    {
        $document = new \DOMDocument();
        // The parser already decoded the part to UTF-8: a leftover <meta charset> would win
        // over the declaration below and turn every accent into mojibake.
        $html = preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html) ?? $html;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    public static function inner(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) { $html .= $node->ownerDocument?->saveHTML($child); }

        return trim($html);
    }

    private function quoteStart(array $lines): ?int
    {
        $count = count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            $plain = trim(preg_replace('/^(?:\s*>)+/', '', $line) ?? '');
            if (str_contains($plain, OutboundEmailBuilder::SENTINEL)) { return $this->attributionAbove($lines, $i); }
            if (preg_match(self::ATTRIBUTION, $line)) { return $i; }
            if ($i + 1 < $count && preg_match(self::ATTRIBUTION_START, $line) && preg_match(self::ATTRIBUTION, $line.' '.$lines[$i + 1])) { return $i; }
            if (preg_match(self::ORIGINAL, $line)) { return $i; }
            if (preg_match(self::HEADER_FROM, $line) && $this->headerBlock($lines, $i)) { return $this->separatorAbove($lines, $i); }
            if ($this->quoted($line)) { return $this->attributionAbove($lines, $i); }
        }

        return null;
    }

    private function headerBlock(array $lines, int $from): bool
    {
        for ($i = $from + 1; $i <= $from + 4 && $i < count($lines); ++$i) {
            if (preg_match(self::HEADER_DATE, $lines[$i])) { return true; }
        }

        return false;
    }

    private function attributionAbove(array $lines, int $index): int
    {
        for ($i = $index - 1; $i >= max(0, $index - 3); --$i) {
            if (trim($lines[$i]) === '') { continue; }
            if (preg_match(self::ATTRIBUTION, $lines[$i]) || ($i > 0 && preg_match(self::ATTRIBUTION, $lines[$i - 1].' '.$lines[$i]))) {
                return $i > 0 && preg_match(self::ATTRIBUTION_START, $lines[$i - 1]) && !preg_match(self::ATTRIBUTION, $lines[$i]) ? $i - 1 : $i;
            }
            break;
        }

        return $index;
    }

    private function separatorAbove(array $lines, int $index): int
    {
        for ($i = $index - 1; $i >= max(0, $index - 2); --$i) {
            if (trim($lines[$i]) === '') { continue; }
            return preg_match(self::SEPARATOR, $lines[$i]) ? $i : $index;
        }

        return $index;
    }

    private function interleaved(array $lines, int $cut): bool
    {
        $seenQuote = false;
        for ($i = $cut; $i < count($lines); ++$i) {
            $line = $lines[$i];
            if (trim($line) === '') { continue; }
            if ($this->quoted($line)) { $seenQuote = true; continue; }
            if (preg_match(self::SIGNATURE, $line) || preg_match(self::MOBILE, $line)) { return false; }
            if ($seenQuote) { return true; }
        }

        return false;
    }

    private function signatureStart(array $lines): ?int
    {
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match(self::SIGNATURE, $line)) { $start = $index; }
        }
        if ($start !== null) { return $start; }
        $tail = array_keys(array_filter($lines, fn(string $line): bool => trim($line) !== ''));
        foreach (array_slice($tail, -2) as $index) {
            if (preg_match(self::MOBILE, $lines[$index]) && $index > 0) { return $index; }
        }

        return null;
    }

    private function quoted(string $line): bool
    {
        return str_starts_with(ltrim($line), '>');
    }

    private static function asQuote(string $text): string
    {
        if ($text === '') { return ''; }

        return implode("\n", array_map(static fn(string $line): string => str_starts_with(ltrim($line), '>') ? $line : rtrim('> '.$line), explode("\n", $text)));
    }
}
