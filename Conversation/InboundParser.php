<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * RFC 5322/MIME decoding through zbateson/mail-mime-parser: charsets, RFC 2047 words and
 * broken mail are where a hand-written parser fails. Only what the channel needs leaves here.
 */
final class InboundParser
{
    public const MAX_ATTACHMENT_BYTES = 26214400;
    private const MAX_ATTACHMENTS = 30;
    private const TRIAGE_HEADERS = ['auto-submitted', 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'precedence', 'x-precedence',
        'list-id', 'list-unsubscribe', 'authentication-results', 'return-path', 'content-type', 'x-failed-recipients'];

    public function parse(string $raw, bool $truncated = false): InboundEmail
    {
        if ($raw === '' || !preg_match('/^[\x21-\x39\x3B-\x7E]+:/', ltrim($raw))) {
            throw new \UnexpectedValueException('Not an RFC 5322 message.');
        }
        $message = (new MailMimeParser())->parse($raw, false);
        $headers = [];
        foreach (self::TRIAGE_HEADERS as $name) {
            foreach ($message->getAllHeadersByName($name) as $header) {
                $headers[$name][] = mb_substr(self::clean($header->getRawValue()), 0, 2000);
            }
        }
        $envelope = [];
        foreach (['Delivered-To', 'X-Original-To', 'Envelope-To', 'X-Envelope-To'] as $name) {
            foreach ($message->getAllHeadersByName($name) as $header) {
                foreach (self::addresses($header) as $address) { $envelope[] = $address['address']; }
            }
        }
        $references = MessageIds::parseList($this->raw($message, 'References'));
        $inReplyTo = MessageIds::parseList($this->raw($message, 'In-Reply-To'), 1)[0] ?? null;
        $from = self::addresses($message->getHeader('From'))[0] ?? ['name' => '', 'address' => ''];
        $text = $truncated ? '' : (string) $message->getTextContent();
        $html = $truncated ? '' : (string) $message->getHtmlContent();
        $attachments = [];
        $report = null;
        if (!$truncated) {
            $report = $this->report($message, $raw);
            foreach ($message->getAllAttachmentParts() as $part) {
                if (count($attachments) >= self::MAX_ATTACHMENTS) { break; }
                $mime = strtolower($part->getContentType('application/octet-stream'));
                // Report parts and forwarded messages are not files the agent asked for.
                if (str_starts_with($mime, 'message/') || in_array($mime, ['text/rfc822-headers', 'application/pgp-signature', 'application/pkcs7-signature'], true)) { continue; }
                $attachments[] = $this->attachment($part, $mime);
            }
        }

        return new InboundEmail(
            MessageIds::normalize($this->raw($message, 'Message-ID')),
            $inReplyTo,
            $references,
            $from,
            self::addresses($message->getHeader('To')),
            self::addresses($message->getHeader('Cc')),
            array_values(array_unique($envelope)),
            mb_substr(self::clean((string) $message->getSubject()), 0, 500),
            mb_substr(self::clean($this->raw($message, 'Date')), 0, 200),
            self::utf8($text),
            self::utf8($html),
            $attachments,
            $headers,
            $report,
            $truncated,
        );
    }

    private function attachment(IMessagePart $part, string $mime): InboundAttachment
    {
        $stream = $part->getBinaryContentStream();
        $content = null;
        $size = 0;
        if ($stream !== null) {
            // Read at most one byte past the cap: anything above it is kept as metadata only.
            $content = $stream->read(self::MAX_ATTACHMENT_BYTES + 1);
            while (!$stream->eof() && strlen($content) <= self::MAX_ATTACHMENT_BYTES) { $content .= $stream->read(self::MAX_ATTACHMENT_BYTES + 1 - strlen($content)); }
            $size = strlen($content);
            if ($size > self::MAX_ATTACHMENT_BYTES) { $content = null; }
        }
        $name = self::clean((string) $part->getFilename());
        $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 160);
        $cid = trim((string) $part->getContentId(), '<> ');

        return new InboundAttachment($name, $mime, $size, $content, $cid === '' ? null : mb_substr(self::clean($cid), 0, 250),
            strtolower((string) $part->getContentDisposition('attachment')) === 'inline');
    }

    /** RFC 3464 delivery-status report, or a classic MAILER-DAEMON notice quoting one of our ids. */
    private function report(IMessage $message, string $raw): ?array
    {
        $type = strtolower($message->getContentType(''));
        $reportType = strtolower((string) $message->getHeaderParameter('Content-Type', 'report-type'));
        $status = null;
        if ($type === 'multipart/report' && in_array($reportType, ['delivery-status', 'global-delivery-status'], true)) {
            $part = $message->getPartByMimeType('message/delivery-status') ?? $message->getPartByMimeType('message/global-delivery-status');
            $status = (string) ($part?->getContent() ?? '');
        } else {
            $sender = strtolower(self::addresses($message->getHeader('From'))[0]['address'] ?? '');
            if (!preg_match('/^(mailer-daemon|postmaster)@/', $sender)) { return null; }
        }
        $original = null;
        foreach (['message/rfc822', 'text/rfc822-headers', 'message/global-headers', 'message/global'] as $mime) {
            $part = $message->getPartByMimeType($mime);
            if ($part !== null && preg_match('/^Message-ID:\s*(<[^<>\s]+>)/mi', (string) $part->getContent(), $match)) { $original = $match[1]; break; }
        }
        if ($original === null) {
            // Some MTAs inline the original headers in the body: only an id this bundle generated counts.
            $own = MessageIds::normalize($this->raw($message, 'Message-ID'));
            preg_match_all('/<[a-f0-9]{32}\.[0-9]+@[^<>\s@]+>/', $raw, $matches);
            foreach ($matches[0] as $candidate) { if ($candidate !== $own) { $original = $candidate; break; } }
        }
        if ($status === null && $original === null) { return null; }
        $field = static function (string $name) use ($status): string {
            return preg_match('/^'.$name.':[ \t]*(.+(?:\r?\n[ \t]+.+)*)/mi', (string) $status, $match) ? mb_substr(self::clean($match[1]), 0, 500) : '';
        };

        return ['action' => strtolower($field('Action')) ?: ($status === null ? 'failed' : ''), 'status' => $field('Status'),
            'diagnostic' => $field('Diagnostic-Code'), 'recipient' => preg_replace('/^rfc822;\s*/i', '', $field('Final-Recipient')) ?? '',
            'original_message_id' => MessageIds::normalize($original)];
    }

    private function raw(IMessage $message, string $name): string
    {
        return (string) $message->getHeader($name)?->getRawValue();
    }

    /** @return list<array{name: string, address: string}> */
    private static function addresses(mixed $header): array
    {
        if (!$header instanceof AddressHeader) { return []; }
        $result = [];
        foreach ($header->getAddresses() as $address) {
            $email = strtolower(trim($address->getEmail()));
            if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
            $result[] = ['name' => mb_substr(self::clean($address->getName()), 0, 150), 'address' => $email];
            if (count($result) >= 50) { break; }
        }

        return $result;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', self::utf8($value)) ?? '');
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
