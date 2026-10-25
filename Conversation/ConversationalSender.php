<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Mailer\ConnectionBuilder;
use Symfony\Component\Mime\Email;

/**
 * Sends through exactly one connection, the one that owns the thread: From and Reply-To
 * belong to that mailbox, so there is no fallback and no `auto` rotation. Same low-level
 * path as the connection test: reserve quota, build, send, finish the reservation.
 */
final class ConversationalSender
{
    public function __construct(private readonly ConnectionStore $store, private readonly ConnectionBuilder $builder) {}

    /** The primary record only: a reserve connection would rewrite the thread's sender. */
    /**
     * A missing connection is permanent (DomainException); an unreadable private store is not,
     * and surfaces as \RuntimeException('Private mail storage …') for the caller to retry.
     */
    public function connection(string $id): array
    {
        try { $connection = $this->store->transportChain($id)[0]; }
        catch (\InvalidArgumentException) { throw new \DomainException('A conexão de e-mail desta conta não existe mais.'); }
        catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'Mail connection unavailable.') { throw new \DomainException('A conexão de e-mail desta conta não existe mais.'); }
            throw $exception;
        }
        if ($connection['provider'] !== 'smtp') { throw new \DomainException('O canal de e-mail conversa só por conexões SMTP.'); }

        return $connection;
    }

    public function send(array $connection, Email $email): SendOutcome
    {
        if ($connection['provider'] !== 'smtp') { throw new \DomainException('O canal de e-mail conversa só por conexões SMTP.'); }
        try {
            $reservation = $this->store->reserve($connection['id'], max(1, count($email->getTo()) + count($email->getCc()) + count($email->getBcc())));
        } catch (\Throwable) {
            // Nothing left this server: safe to try again later.
            return new SendOutcome(SendOutcome::TEMPORARY, 'Controle de cota indisponível no momento.');
        }
        if (!$reservation['allowed']) {
            return new SendOutcome(SendOutcome::TEMPORARY, 'Limite de envio da conexão atingido.', $reservation['retry_at'] ?? null);
        }
        $attempt = null;
        try {
            $attempt = $this->builder->build($connection);
            if ($attempt->transport->send($email) === null) {
                $this->finish($reservation, 'uncertain');
                return new SendOutcome(SendOutcome::UNCERTAIN, 'O servidor SMTP não confirmou o envio.');
            }
            $this->finish($reservation, 'accepted');

            return new SendOutcome(SendOutcome::ACCEPTED);
        } catch (\Throwable) {
            // Never propagate transport exceptions: their transcripts can include AUTH data.
            if ($attempt !== null && !$attempt->confirmedNotAccepted()) {
                $this->finish($reservation, 'uncertain');
                return new SendOutcome(SendOutcome::UNCERTAIN, 'A conexão caiu depois do envio do conteúdo; o resultado é incerto.');
            }
            $this->finish($reservation, 'rejected');
            $refusal = $attempt?->smtpRefusal();
            if ($refusal === null) { return new SendOutcome(SendOutcome::TEMPORARY, 'Servidor SMTP indisponível.'); }
            if ($refusal['code'] >= 500 && in_array($refusal['stage'], ['rcpt', 'data'], true)) {
                return new SendOutcome(SendOutcome::PERMANENT, $refusal['response'] !== '' ? $refusal['response'] : 'SMTP '.$refusal['code'], null, $refusal['code']);
            }
            $reason = $refusal['stage'] === 'session' ? 'O servidor SMTP recusou a sessão ('.$refusal['code'].').' : ($refusal['response'] !== '' ? $refusal['response'] : 'SMTP '.$refusal['code']);

            return new SendOutcome(SendOutcome::TEMPORARY, $reason, null, $refusal['code']);
        } finally { $attempt?->close(); }
    }

    private function finish(array $reservation, string $outcome): void
    {
        // Capacity is already charged; a failed bookkeeping write must not change the send result.
        try { $this->store->finishReservation($reservation['token'], $outcome); } catch (\Throwable) {}
    }
}
