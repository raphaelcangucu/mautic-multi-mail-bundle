<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Email, Address};
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
        $quotaBlocked = false;
        $retryAt = null;
        foreach ($chain as $connection) {
            $attempt = null;
            $reservation = null;
            try {
                $reservation = $this->store->reserve($connection['id'], count($message->getEnvelope()->getRecipients()));
                if (!$reservation['allowed']) {
                    $quotaBlocked = true;
                    $candidate = isset($reservation['retry_at']) ? strtotime($reservation['retry_at']) : time() + 3600;
                    $retryAt = $retryAt === null ? $candidate : min($retryAt, $candidate);
                    continue;
                }
                $attempt = $this->builder->build($connection);
                $original = $message->getOriginalMessage();
                $envelope = $message->getEnvelope();
                if ($this->connectionId === 'auto' && $original instanceof Email) {
                    $original = clone $original;
                    // Global rotation uses each verified account's configured sender. Explicit choices preserve Mautic's sender.
                    $sender = new Address($connection['from_email'], $connection['from_name']);
                    $original->from($sender)->sender($sender);
                    $original->getHeaders()->remove('Reply-To');
                    if ($connection['reply_to'] !== '') { $original->replyTo($connection['reply_to']); }
                    $envelope = new Envelope($sender, $envelope->getRecipients());
                }
                $sent = $attempt->transport->send($original, $envelope);
                if ($sent === null) { throw new TransportException('Mail not accepted.'); }
                $this->finish($reservation['token'], 'accepted');
                $reservation = null;
                $message->setMessageId($sent->getMessageId());

                return;
            } catch (\Throwable) {
                if ($attempt !== null && !$attempt->confirmedNotAccepted()) {
                    if ($reservation !== null && isset($reservation['token'])) { $this->finish($reservation['token'], 'uncertain'); }
                    // A timeout after handoff may already have queued the message: avoid duplicate delivery.
                    throw new TransportException('Multi Mail: resultado do envio incerto; fallback não executado.');
                }
                if ($reservation !== null && isset($reservation['token'])) { $this->finish($reservation['token'], 'rejected'); }
                // No provider accepted the message. Try the configured next connection.
            } finally { $attempt?->close(); }
        }
        if ($quotaBlocked) { throw new QuotaExceededException($retryAt ?? time() + 3600); }
        // Never propagate provider exceptions/debug transcripts, which can contain authentication data.
        throw new TransportException('Multi Mail: nenhuma conexão aceitou o envio.');
    }

    private function finish(string $token, string $outcome): void
    {
        // A persisted reservation already charged capacity. Finalization failure must never retry an accepted email.
        try { $this->store->finishReservation($token, $outcome); } catch (\Throwable) { /* Retain the conservative reservation. */ }
    }
}
