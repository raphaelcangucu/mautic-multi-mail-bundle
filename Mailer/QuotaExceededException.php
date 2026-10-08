<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;

final class QuotaExceededException extends TransportException
{
    public const MARKER = 'Multi Mail hourly quota retry_at=';

    public function __construct(int $retryAt)
    {
        parent::__construct(self::MARKER.$retryAt);
    }
}
