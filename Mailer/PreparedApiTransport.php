<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\{Message, RawMessage};

/** Normalize streams and envelope categories before the official API bridges. */
final class PreparedApiTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $transport) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$message instanceof Message) { throw new TransportException('An API connection requires a MIME message.'); }
        $envelope ??= Envelope::create($message);
        return $this->transport->send(ApiEmail::prepare($message, $envelope), $envelope);
    }

    public function __toString(): string { return (string) $this->transport; }
}
