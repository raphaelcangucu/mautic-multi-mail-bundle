<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\HttpTransportException;

/** Only a documented, confirmed refusal may suspend the shared account. */
final class ProviderQuota
{
    public static function period(string $provider, ?int $status, \Throwable $exception): ?string
    {
        if ($provider !== 'resend' || $status !== 429 || !$exception instanceof HttpTransportException) { return null; }
        try {
            return match ($exception->getResponse()->toArray(false)['name'] ?? null) {
                'daily_quota_exceeded' => 'daily',
                'monthly_quota_exceeded' => 'monthly',
                default => null,
            };
        } catch (\Throwable) { return null; }
    }
}
