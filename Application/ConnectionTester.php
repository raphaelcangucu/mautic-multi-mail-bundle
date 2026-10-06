<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, NativeTransportResolver};
use Symfony\Component\Mime\{Address, Email};

/** A single explicit diagnostic send, independent of Mautic's global transport. */
final class ConnectionTester
{
    public function __construct(private readonly ConnectionStore $store, private readonly ConnectionBuilder $builder,
        private readonly ?NativeTransportResolver $native = null) {}

    public function send(string $id, string $recipient, int $revision): array
    {
        if (strlen($recipient) > 254 || preg_match('/[\x00-\x20\x7F]/', $recipient)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('mautic.multimail.test.invalid_recipient');
        }
        // Read once and use only the primary: a reserve must not hide a broken connection.
        $connection = $this->store->transportChain($id, $revision)[0];
        $reference = bin2hex(random_bytes(6));
        $email = (new Email())->from(new Address($connection['from_email'], $connection['from_name']))
            ->to($recipient)->subject('[Multi Mail] '.$connection['name'].' · '.$reference)
            ->text("Multi Mail connection test\nConnection: ".$connection['name']."\nProvider: ".$connection['provider']
                ."\nReference: ".$reference."\nSent at: ".gmdate(DATE_ATOM)."\n\nThis is a diagnostic email, not a campaign.");
        if ($connection['reply_to'] !== '') { $email->replyTo($connection['reply_to']); }
        $attempt = null;
        $invoked = false;
        try {
            if ($connection['provider'] === 'native') {
                if ($this->native === null) { throw new \RuntimeException('Native factory unavailable.'); }
                $transport = $this->native->resolve($connection['secrets']['dsn']);
            } else {
                $attempt = $this->builder->build($connection);
                $transport = $attempt->transport;
            }
            $invoked = true;
            $sent = $transport->send($email);
            if ($sent === null) { return ['status' => 'rejected', 'reference' => $reference]; }
            // Acceptance means provider handoff, not confirmed delivery to the recipient's inbox.
            return ['status' => 'accepted', 'reference' => $reference];
        } catch (\Throwable) {
            // Never return provider debug output, authentication data or DSNs.
            return ['status' => !$invoked || ($attempt !== null && $attempt->confirmedNotAccepted()) ? 'rejected' : 'uncertain', 'reference' => $reference];
        } finally { $attempt?->close(); }
    }
}
