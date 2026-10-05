<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Distinguish a refusal from a broken connection after the message body was sent. */
final class ConfirmedSmtpTransport extends EsmtpTransport
{
    private bool $confirmedNotAccepted = true;

    public function executeCommand(#[\SensitiveParameter] string $command, array $codes): string
    {
        try {
            $response = parent::executeCommand($command, $codes);
            if ($command === "DATA\r\n") {
                // From this point onward an absent final response has an uncertain outcome.
                $this->confirmedNotAccepted = false;
            }

            return $response;
        } catch (UnexpectedResponseException $exception) {
            if ($command === "\r\n.\r\n" && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
                $this->confirmedNotAccepted = true;
            }
            throw $exception;
        }
    }

    public function confirmedNotAccepted(): bool
    {
        return $this->confirmedNotAccepted;
    }
}
