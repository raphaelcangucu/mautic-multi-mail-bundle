<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

/** The server refused the credentials: polling stops until the connection is saved again. */
final class ImapAuthenticationFailed extends ImapException
{
}
