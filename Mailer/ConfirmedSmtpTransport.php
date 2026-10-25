<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Distinguish a refusal from a broken connection after the message body was sent. */
final class ConfirmedSmtpTransport extends EsmtpTransport
{
    private bool $confirmedNotAccepted = true;
    /** @var array{stage: string, code: int, response: string}|null */
    private ?array $refusal = null;

    public function executeCommand(#[\SensitiveParameter] string $command, array $codes): string
    {
        try {
            $response = parent::executeCommand($command, $codes);
            if ($command === "DATA\r\n") {
                // From this point onward an absent final response has an uncertain outcome.
                $this->confirmedNotAccepted = false;
            }
            if (str_starts_with($command, 'MAIL FROM:')) {
                // A mechanism refused during AUTH negotiation is not the cause of a later failure.
                $this->refusal = null;
            }

            return $response;
        } catch (UnexpectedResponseException $exception) {
            if ($command === "\r\n.\r\n" && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
                $this->confirmedNotAccepted = true;
            }
            // Only the stage and the server's own reply line: never the command, which can carry AUTH data.
            $stage = match (true) {
                $command === "\r\n.\r\n" => 'data',
                str_starts_with($command, 'RCPT TO:') => 'rcpt',
                str_starts_with($command, 'MAIL FROM:') => 'mail',
                default => 'session',
            };
            $response = preg_match('/with message "(.*)"\.?$/s', $exception->getMessage(), $match) ? $match[1] : '';
            // The last refusal is the cause (EHLO falls back to HELO); RSET and QUIT are cleanup.
            if (!in_array($command, ["RSET\r\n", "QUIT\r\n"], true)) {
                $this->refusal = ['stage' => $stage, 'code' => (int) $exception->getCode(),
                    'response' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $response) ?? ''), 0, 300)];
            }
            throw $exception;
        }
    }

    public function confirmedNotAccepted(): bool
    {
        return $this->confirmedNotAccepted;
    }

    /** @return array{stage: string, code: int, response: string}|null the command that was refused */
    public function refusal(): ?array
    {
        return $this->refusal;
    }
}
