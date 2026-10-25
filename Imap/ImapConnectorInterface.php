<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** Opens an authenticated session for a connection record; tests replace it with a scripted server. */
interface ImapConnectorInterface
{
    public function connect(#[\SensitiveParameter] array $connection): ImapClient;
}
