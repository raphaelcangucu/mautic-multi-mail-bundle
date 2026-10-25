<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

/** Classification that happens before any threading. Pure: the echo lookup is the caller's. */
final class Triage
{
    public const MESSAGE = 'message';
    public const AUTO_REPLY = 'auto_reply';
    public const BOUNCE = 'bounce';

    private const VACATION = '/^\s*(?:auto(?:matic)?[ -]?(?:reply|response)|out of (?:the )?office|resposta autom[aá]tica|aus[eê]ncia|f[eé]rias|respuesta autom[aá]tica|fuera de la oficina|automatische antwort|abwesenheit|r[eé]ponse automatique|absence)\b/iu';

    public static function kind(InboundEmail $email): string
    {
        if ($email->report !== null) { return self::BOUNCE; }

        return self::isAutoReply($email) ? self::AUTO_REPLY : self::MESSAGE;
    }

    /** Auto-Submitted other than "no", X-Autoreply, X-Autorespond, auto_reply precedence or a vacation subject. */
    public static function isAutoReply(InboundEmail $email): bool
    {
        $submitted = strtolower(trim((string) $email->header('auto-submitted')));
        if ($submitted !== '' && $submitted !== 'no') { return true; }
        if ($email->header('x-autoreply') !== null || $email->header('x-autorespond') !== null) { return true; }
        foreach (['precedence', 'x-precedence'] as $name) {
            if (strtolower(trim((string) $email->header($name))) === 'auto_reply') { return true; }
        }

        return (bool) preg_match(self::VACATION, $email->subject);
    }

    /** Mailing list or bulk mail: ignored unless it is linked to an existing conversation. */
    public static function isBulk(InboundEmail $email): bool
    {
        if ($email->header('list-id') !== null) { return true; }
        foreach (['precedence', 'x-precedence'] as $name) {
            if (in_array(strtolower(trim((string) $email->header($name))), ['bulk', 'list', 'junk'], true)) { return true; }
        }

        return false;
    }

    /** dmarc=fail in the receiving mailbox's own (topmost) Authentication-Results. */
    public static function dmarcFailed(InboundEmail $email): bool
    {
        return (bool) preg_match('/\bdmarc\s*=\s*fail\b/i', (string) $email->header('authentication-results'));
    }

    /** Our own outgoing copy showing up in the mailbox. */
    public static function isEcho(InboundEmail $email, string $accountAddress): bool
    {
        return strtolower($email->from['address']) === strtolower($accountAddress)
            && $email->messageId !== null && MessageIds::looksOwn($email->messageId);
    }
}
