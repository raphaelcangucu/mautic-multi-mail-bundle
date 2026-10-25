<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use Mautic\CoreBundle\Helper\CoreParametersHelper;

/**
 * clamd INSTREAM over the socket in the `multimail_clamd_socket` parameter
 * (`unix:///run/clamav/clamd.ctl` or `tcp://127.0.0.1:3310`). Without it every
 * attachment stays unavailable: an unscanned file is never served.
 */
final class ClamdScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const UNAVAILABLE = 'unscanned';
    public const ERROR = 'error';

    public function __construct(private readonly ?CoreParametersHelper $parameters = null) {}

    public function configured(?string $socket = null): bool
    {
        return ($socket ?? $this->socket()) !== null;
    }

    public function scan(string $bytes, ?string $socket = null): string
    {
        $socket ??= $this->socket();
        if ($socket === null) { return self::UNAVAILABLE; }
        if (!preg_match('#^(?:unix:///[^\s\x00]{1,200}|tcp://[a-z0-9.-]{1,253}:[0-9]{1,5})$#Di', $socket)) { return self::ERROR; }
        $errno = 0; $error = '';
        $stream = @stream_socket_client($socket, $errno, $error, 5);
        if (!is_resource($stream)) { return self::ERROR; }
        stream_set_timeout($stream, 60);
        try {
            if (@fwrite($stream, "zINSTREAM\0") === false) { return self::ERROR; }
            for ($offset = 0; $offset < strlen($bytes); $offset += 65536) {
                $chunk = substr($bytes, $offset, 65536);
                if (@fwrite($stream, pack('N', strlen($chunk)).$chunk) === false) { return self::ERROR; }
            }
            @fwrite($stream, pack('N', 0));
            $response = trim((string) stream_get_contents($stream, 4096), "\0\r\n ");
            if (preg_match('/^stream: OK$/D', $response)) { return self::CLEAN; }

            return preg_match('/ FOUND$/D', $response) ? self::INFECTED : self::ERROR;
        } finally { fclose($stream); }
    }

    private function socket(): ?string
    {
        try { $socket = $this->parameters?->get('multimail_clamd_socket'); } catch (\Throwable) { $socket = null; }

        return is_string($socket) && trim($socket) !== '' ? trim($socket) : null;
    }
}
