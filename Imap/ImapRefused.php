<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** Tagged NO or BAD. The text is the server's, stripped of control characters and truncated. */
final class ImapRefused extends ImapException
{
    public function __construct(public readonly string $status, string $text)
    {
        parent::__construct('IMAP '.$status.': '.mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '', 0, 200));
    }
}
