<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** TLS on 993 or STARTTLS on 143; the IMAP user and password default to the SMTP ones. */
final class ImapConnector implements ImapConnectorInterface
{
    public function connect(#[\SensitiveParameter] array $connection): ImapClient
    {
        $settings = $connection['settings'];
        if (($settings['imap_host'] ?? '') === '') { throw new ImapException('IMAP is not configured for this connection.'); }
        $client = ImapClient::open($settings['imap_host'], (int) $settings['imap_port'], 20);
        try {
            $client->login(
                ($settings['imap_username'] ?? '') !== '' ? $settings['imap_username'] : (string) ($settings['username'] ?? ''),
                ($connection['secrets']['imap_password'] ?? '') !== '' ? $connection['secrets']['imap_password'] : (string) ($connection['secrets']['password'] ?? ''),
            );
        } catch (\Throwable $exception) {
            $client->logout();
            throw $exception;
        }

        return $client;
    }
}
