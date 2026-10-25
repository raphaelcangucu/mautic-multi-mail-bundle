<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** Byte transport under the IMAP client; tests replace it with a scripted server. */
interface ImapStream
{
    /** One line including its CRLF; throws on timeout, EOF or a line longer than $max. */
    public function readLine(int $max): string;

    /** Exactly $bytes bytes (literal payload). */
    public function read(int $bytes): string;

    public function write(string $bytes): void;

    /** Upgrade after STARTTLS, verifying the certificate against $peerName. */
    public function enableTls(string $peerName): void;

    public function close(): void;
}
