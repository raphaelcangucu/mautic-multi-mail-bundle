<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;

/** Safe classification only: never retain response bodies, recipients or credentials. */
final class ProviderResponseException extends TransportException
{
    public function __construct(public readonly bool $rejected)
    {
        parent::__construct($rejected ? 'Mail provider refused the message.' : 'Mail provider handoff is uncertain.');
    }
}
