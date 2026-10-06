<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Contracts\HttpClient\ResponseInterface;

/** Provider-specific success means every requested recipient was accepted. */
final class ProviderResponseGuard
{
    public static function acceptedId(string $provider, ResponseInterface $response, #[\SensitiveParameter] array $payload): string
    {
        $status = $response->getStatusCode();
        $success = $provider === 'mailersend' ? 202 : 200;
        if ($status !== $success) {
            // SparkPost 422 can include already accepted recipients. Timeouts/5xx are ambiguous.
            $rejected = in_array($status, [400, 401, 403, 404, 405, 413, 415, 429], true)
                || ($status === 422 && $provider !== 'sparkpost');
            throw new ProviderResponseException($rejected);
        }
        try {
            $content = $response->getContent(false);
            $data = $content === '' && $provider === 'mailersend' ? [] : $response->toArray(false);
        } catch (\Throwable) { throw new ProviderResponseException(false); }

        switch ($provider) {
            case 'mailjet':
                $messages = $data['Messages'] ?? null;
                if (!is_array($messages) || count($messages) !== 1 || !is_array($messages[0] ?? null)) { throw new ProviderResponseException(false); }
                $message = $messages[0];
                if (($message['Status'] ?? null) === 'error') { throw new ProviderResponseException(true); }
                if (($message['Status'] ?? null) !== 'success') { throw new ProviderResponseException(false); }
                $ids = [];
                foreach (['To', 'Cc', 'Bcc'] as $category) {
                    $expected = array_column($payload['Messages'][0][$category] ?? [], 'Email');
                    $actual = $message[$category] ?? [];
                    if (!is_array($actual) || count($actual) !== count($expected)) { throw new ProviderResponseException(false); }
                    if (array_diff($expected, array_column($actual, 'Email')) || array_diff(array_column($actual, 'Email'), $expected)) { throw new ProviderResponseException(false); }
                    foreach ($actual as $recipient) { $ids[] = self::safeId($recipient['MessageID'] ?? null); }
                }
                return $ids[0] ?? throw new ProviderResponseException(false);
            case 'mailersend':
                $warnings = $data['warnings'] ?? [];
                if (!is_array($warnings)) { throw new ProviderResponseException(false); }
                foreach ($warnings as $warning) {
                    if (($warning['type'] ?? null) === 'ALL_SUPPRESSED') { throw new ProviderResponseException(true); }
                }
                if ($warnings !== []) { throw new ProviderResponseException(false); }
                return self::safeId($response->getHeaders(false)['x-message-id'][0] ?? null);
            case 'mandrill':
                if (($data['status'] ?? null) === 'error') { throw new ProviderResponseException(true); }
                $expected = array_column($payload['message']['to'] ?? [], 'email');
                if (!array_is_list($data) || count($data) !== count($expected) || $data === []) { throw new ProviderResponseException(false); }
                if (array_diff($expected, array_column($data, 'email')) || array_diff(array_column($data, 'email'), $expected)) { throw new ProviderResponseException(false); }
                $accepted = $refused = 0;
                foreach ($data as $recipient) {
                    if (in_array($recipient['status'] ?? null, ['sent', 'queued', 'scheduled'], true)) { ++$accepted; }
                    elseif (in_array($recipient['status'] ?? null, ['rejected', 'invalid'], true)) { ++$refused; }
                }
                if ($accepted !== count($expected)) { throw new ProviderResponseException($refused === count($expected)); }
                foreach ($data as $recipient) { self::safeId($recipient['_id'] ?? null); }
                return self::safeId($data[0]['_id'] ?? null);
            case 'sparkpost':
                $results = $data['results'] ?? [];
                $accepted = $results['total_accepted_recipients'] ?? null;
                $rejected = $results['total_rejected_recipients'] ?? null;
                $expected = count($payload['recipients'] ?? []);
                if ($accepted !== $expected || $rejected !== 0 || $expected === 0 || !empty($data['errors'])) {
                    throw new ProviderResponseException($accepted === 0 && $rejected === $expected && $expected > 0);
                }
                return self::safeId($results['id'] ?? null);
            case 'smtp2go':
                $results = $data['data'] ?? [];
                if (isset($results['error_code'])) { throw new ProviderResponseException(true); }
                $expected = count($payload['to'] ?? []) + count($payload['cc'] ?? []) + count($payload['bcc'] ?? []);
                $accepted = $results['succeeded'] ?? null;
                $failed = $results['failed'] ?? null;
                if ($accepted !== $expected || $failed !== 0 || !empty($results['failures']) || $expected === 0) {
                    throw new ProviderResponseException($accepted === 0 && $failed === $expected && $expected > 0);
                }
                return self::safeId($results['email_id'] ?? null);
        }
        throw new ProviderResponseException(false);
    }

    private static function safeId(mixed $id): string
    {
        if (is_int($id)) { $id = (string) $id; }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $id)) { throw new ProviderResponseException(false); }
        return $id;
    }
}
