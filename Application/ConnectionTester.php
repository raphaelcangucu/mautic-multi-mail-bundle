<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Application;

use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, NativeTransportResolver};
use Symfony\Component\Mailer\Exception\HttpTransportException;
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
            $result = ['status' => 'accepted', 'reference' => $reference];
            if ($connection['provider'] === 'resend') {
                // The bridge replaces the MIME ID with Resend's API ID. Do not discard it.
                $messageId = $sent->getMessageId();
                if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $messageId)) {
                    return ['status' => 'uncertain', 'reference' => $reference, 'http_status' => $attempt?->httpStatus(), 'error_code' => 'invalid_response'];
                }
                $result['provider_message_id'] = $messageId;
            }
            if ($attempt?->httpStatus() !== null) { $result['http_status'] = $attempt->httpStatus(); }
            // Acceptance means provider handoff, not confirmed delivery to the recipient's inbox.
            return $result;
        } catch (\Throwable $exception) {
            // Never return provider debug output, authentication data or DSNs.
            $httpStatus = $attempt?->httpStatus();
            $rejected = !$invoked || ($attempt !== null && $attempt->confirmedNotAccepted());
            if ($connection['provider'] === 'resend' && in_array($httpStatus, [400, 404, 405, 422], true)) { $rejected = true; }
            $result = ['status' => $rejected ? 'rejected' : 'uncertain', 'reference' => $reference];
            if ($httpStatus !== null) { $result['http_status'] = $httpStatus; }
            if ($connection['provider'] === 'resend' && $httpStatus !== null) {
                $result['error_code'] = $httpStatus === 200 ? 'invalid_response' : 'provider_error';
                if ($exception instanceof HttpTransportException) {
                    try {
                        $name = $exception->getResponse()->toArray(false)['name'] ?? null;
                        if (is_string($name) && in_array($name, [
                            'validation_error', 'missing_api_key', 'invalid_api_key', 'restricted_api_key',
                            'suspended_api_key', 'invalid_permission', 'rate_limit_exceeded',
                            'daily_quota_exceeded', 'monthly_quota_exceeded', 'invalid_attachment',
                            'invalid_parameter', 'missing_required_field', 'application_error', 'service_unavailable',
                        ], true)) { $result['error_code'] = $name; }
                    } catch (\Throwable) { /* Malformed error bodies must not expose raw content. */ }
                }
            }
            return $result;
        } finally { $attempt?->close(); }
    }
}
