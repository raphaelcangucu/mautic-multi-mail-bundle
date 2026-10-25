<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** Network, protocol or server refusal. Messages never contain credentials or message content. */
class ImapException extends \RuntimeException
{
}
