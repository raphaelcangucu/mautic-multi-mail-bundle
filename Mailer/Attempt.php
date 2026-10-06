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
}
