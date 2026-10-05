<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class ConnectionTransport extends AbstractTransport
{
    public function __construct(private readonly string $connectionId, private readonly ConnectionStore $store,
        private readonly ConnectionBuilder $builder, ?EventDispatcherInterface $dispatcher = null)
    {
        parent::__construct($dispatcher);
    }

    public function __toString(): string
    {
        return 'multimail://'.$this->connectionId;
    }

    protected function doSend(SentMessage $message): void
    {
        try { $chain = $this->store->transportChain($this->connectionId); }
        catch (\Throwable) { throw new TransportException('Multi Mail: conexão privada indisponível.'); }
        foreach ($chain as $connection) {
            $attempt = null;
            try {
                $attempt = $this->builder->build($connection);
                // Keep Mautic's From, Reply-To, MIME, attachments, tracking headers and envelope.
                $sent = $attempt->transport->send($message->getOriginalMessage(), $message->getEnvelope());
                if ($sent === null) { throw new TransportException('Mail not accepted.'); }
                $message->setMessageId($sent->getMessageId());

                return;
            } catch (\Throwable) {
                if ($attempt !== null && !$attempt->confirmedNotAccepted()) {
                    // A timeout after handoff may already have queued the message: avoid duplicate delivery.
                    throw new TransportException('Multi Mail: resultado do envio incerto; fallback não executado.');
                }
                // No provider accepted the message. Try the configured next connection.
            } finally { $attempt?->close(); }
        }
        // Never propagate provider exceptions/debug transcripts, which can contain authentication data.
        throw new TransportException('Multi Mail: nenhuma conexão aceitou o envio.');
    }
}
