<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/**
 * The WhatsQR 0.3.0 attachment rules: a MIME allow-list per type, an extension that agrees
 * with it and the content's own signature winning over the declared type.
 */
final class AttachmentPolicy
{
    public const TYPES = [
        'image' => ['image/jpeg' => ['jpg', 'jpeg', 'jpe'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp']],
        'video' => ['video/mp4' => ['mp4', 'm4v'], 'video/webm' => ['webm'], 'video/3gpp' => ['3gp', '3gpp']],
        'audio' => ['audio/aac' => ['aac'], 'audio/amr' => ['amr'], 'audio/ogg' => ['ogg', 'oga', 'opus'], 'audio/mpeg' => ['mp3', 'mpga'],
            'audio/mp4' => ['m4a', 'mp4'], 'audio/wave' => ['wav'], 'audio/webm' => ['weba', 'webm']],
        'document' => ['application/pdf' => ['pdf'], 'text/plain' => ['txt', 'text', 'log'], 'text/csv' => ['csv'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx']],
    ];
    private const ALIASES = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'audio/wav' => 'audio/wave', 'audio/x-wav' => 'audio/wave',
        'audio/vnd.wave' => 'audio/wave', 'audio/mp3' => 'audio/mpeg', 'audio/x-m4a' => 'audio/mp4', 'application/csv' => 'text/csv', 'text/x-csv' => 'text/csv'];

    /** @return array{type: string, mime: string, allowed: bool} */
    public static function evaluate(string $declaredMime, string $name, string $content): array
    {
        $declared = self::alias(strtolower(trim(explode(';', $declaredMime)[0])));
        $sniffed = self::sniff($content);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $sniffed ?? $declared;
        // Office Open XML is a ZIP and plain-text formats are only "text": the declaration decides between candidates the signature allows.
        if (in_array($sniffed, ['application/zip', 'application/octet-stream'], true) && str_starts_with($declared, 'application/vnd.openxmlformats-officedocument.')) { $mime = $declared; }
        if ($sniffed === 'text/plain' && $declared === 'text/csv') { $mime = 'text/csv'; }
        if ($sniffed === 'audio/mp4' && $declared === 'video/mp4') { $mime = 'video/mp4'; }
        foreach (self::TYPES as $type => $mimes) {
            if (!isset($mimes[$mime])) { continue; }
            $allowed = ($extension === '' || in_array($extension, $mimes[$mime], true)) && ($sniffed === null || $sniffed === $mime || $mime === $declared);

            return ['type' => $type, 'mime' => $mime, 'allowed' => $allowed && $content !== ''];
        }

        return ['type' => 'document', 'mime' => $mime, 'allowed' => false];
    }

    private static function sniff(string $content): ?string
    {
        if ($content === '' || !function_exists('finfo_open')) { return null; }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $info === false ? false : finfo_buffer($info, substr($content, 0, 1048576));
        if ($info !== false) { finfo_close($info); }

        return is_string($mime) && $mime !== '' ? self::alias(strtolower($mime)) : null;
    }

    private static function alias(string $mime): string
    {
        return self::ALIASES[$mime] ?? $mime;
    }
}
