<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Transport\TransportInterface;

final class Attempt
{
    public function __construct(public readonly TransportInterface $transport, private readonly ConfirmedSmtpTransport|OutcomeHttpClient $outcome)
    {
    }

    public function confirmedNotAccepted(): bool
    {
        return $this->outcome->confirmedNotAccepted();
    }

    /** @return array{stage: string, code: int, response: string}|null */
    public function smtpRefusal(): ?array
    {
        return $this->outcome instanceof ConfirmedSmtpTransport ? $this->outcome->refusal() : null;
    }

    public function httpStatus(): ?int
    {
        return $this->outcome instanceof OutcomeHttpClient ? $this->outcome->statusCode() : null;
    }

    public function close(): void
    {
        if ($this->transport instanceof ConfirmedSmtpTransport) {
            try { $this->transport->stop(); } catch (\Throwable) { /* Shutdown must not change a send's result. */ }
        }
    }

    public function providerMessageId(): ?string
    {
        return $this->outcome instanceof OutcomeHttpClient ? $this->outcome->providerMessageId() : null;
    }
}
