<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/**
 * TCP stream with TLS that always verifies the peer: implicit TLS on 993, STARTTLS on 143.
 * There is deliberately no option to skip verification; test servers use a trusted local CA.
 */
final class SocketImapStream implements ImapStream
{
    /** @param resource $socket */
    private function __construct(private $socket) {}

    public static function open(string $host, int $port, bool $implicitTls, int $timeout = 20): self
    {
        $context = stream_context_create(['ssl' => self::tlsOptions($host)]);
        $errno = 0; $error = '';
        $socket = @stream_socket_client(($implicitTls ? 'tls://' : 'tcp://').$host.':'.$port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new ImapException('IMAP connection failed: '.self::safe($error !== '' ? $error : 'unreachable').'.');
        }
        stream_set_timeout($socket, $timeout);

        return new self($socket);
    }

    public function readLine(int $max): string
    {
        $line = '';
        while (!str_ends_with($line, "\n")) {
            $chunk = fgets($this->socket, min(65536, $max - strlen($line) + 1));
            if ($chunk === false || $chunk === '') { $this->failure(); }
            $line .= $chunk;
            if (strlen($line) > $max) { throw new ImapException('IMAP response line too long.'); }
        }

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->socket, min(1048576, $bytes - strlen($data)));
            if ($chunk === false || $chunk === '') { $this->failure(); }
            $data .= $chunk;
        }

        return $data;
    }

    public function write(string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($this->socket, $bytes);
            if ($written === false || $written === 0) { $this->failure(); }
            $bytes = substr($bytes, $written);
        }
    }

    public function enableTls(string $peerName): void
    {
        foreach (self::tlsOptions($peerName) as $option => $value) { stream_context_set_option($this->socket, 'ssl', $option, $value); }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
            throw new ImapException('IMAP STARTTLS negotiation failed.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) { @fclose($this->socket); }
    }

    private static function tlsOptions(string $peerName): array
    {
        return ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $peerName,
            'SNI_enabled' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)];
    }

    private function failure(): never
    {
        $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : ['timed_out' => false];
        throw new ImapException(($meta['timed_out'] ?? false) ? 'IMAP server timed out.' : 'IMAP connection closed.');
    }

    private static function safe(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '', 0, 200);
    }
}
