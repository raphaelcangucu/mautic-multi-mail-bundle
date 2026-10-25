<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use Symfony\Component\Mime\{Address, Email};

/**
 * MIME of a conversational reply. The Message-ID is always explicit (the bundle never let
 * the transport choose one), so a retried job produces the same message and the lead's
 * client drops the duplicate. No tracking pixel and no rewritten links.
 */
final class OutboundEmailBuilder
{
    public const SENTINEL = '##- Responda acima desta linha -##';
    public const MAX_REFERENCES = 10;

    public function __construct(private readonly MarkdownRenderer $markdown = new MarkdownRenderer()) {}

    /**
     * @param array{from_email: string, from_name: string, reply_to: ?string, to_email: string, to_name?: ?string,
     *     subject: string, text: string, message_id: string, in_reply_to?: ?string, references?: list<string>,
     *     unsubscribe_url?: ?string} $spec
     */
    public function build(array $spec): Email
    {
        $messageId = MessageIds::normalize($spec['message_id'] ?? null) ?? throw new \InvalidArgumentException('Invalid Message-ID.');
        $subject = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($spec['subject'] ?? '')) ?? '');
        if ($subject === '') { throw new \InvalidArgumentException('O assunto do e-mail é obrigatório.'); }
        $text = (string) ($spec['text'] ?? '');
        if (trim($text) === '') { throw new \InvalidArgumentException('O texto do e-mail é obrigatório.'); }
        $email = (new Email())
            ->from(new Address($spec['from_email'], self::displayName($spec['from_name'] ?? '')))
            ->to(new Address(strtolower($spec['to_email']), self::displayName($spec['to_name'] ?? '')))
            ->subject(mb_substr($subject, 0, 250))
            ->text(self::SENTINEL."\n\n".$this->markdown->toText($text)."\n")
            ->html($this->html($text));
        if (($spec['reply_to'] ?? '') !== '' && $spec['reply_to'] !== null) { $email->replyTo($spec['reply_to']); }
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', trim($messageId, '<>'));
        $inReplyTo = MessageIds::normalize($spec['in_reply_to'] ?? null);
        $references = [];
        foreach ($spec['references'] ?? [] as $reference) {
            if (($reference = MessageIds::normalize($reference)) !== null) { $references[strtolower($reference)] = $reference; }
        }
        if ($inReplyTo !== null) {
            unset($references[strtolower($inReplyTo)]);
            $references[strtolower($inReplyTo)] = $inReplyTo;
            $headers->addIdHeader('In-Reply-To', trim($inReplyTo, '<>'));
        }
        $references = array_slice(array_values($references), -self::MAX_REFERENCES);
        if ($references !== []) {
            $headers->addIdHeader('References', array_map(static fn(string $id): string => trim($id, '<>'), $references));
        }
        $unsubscribe = $spec['unsubscribe_url'] ?? null;
        if ($unsubscribe !== null && $unsubscribe !== '') {
            // Campaign opt-in only: RFC 8058 one-click. A human reply never carries it.
            if (!preg_match('/^https:\/\/[^\s<>"]{1,2000}$/D', $unsubscribe)) { throw new \InvalidArgumentException('Invalid unsubscribe URL.'); }
            $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $email;
    }

    /** `local+r{token}@domain`, or the account address itself when the mailbox has no +tag. */
    public static function replyTo(string $accountAddress, ?string $token): string
    {
        if ($token === null) { return $accountAddress; }
        $at = strrpos($accountAddress, '@');
        if ($at === false || !preg_match('/^r[a-z2-7]{24}$/D', $token)) { throw new \InvalidArgumentException('Invalid reply address.'); }

        return substr($accountAddress, 0, $at).'+'.$token.substr($accountAddress, $at);
    }

    /** "{agent} · {connection}" for a human reply; the campaign name or the connection name otherwise. */
    public static function senderName(?string $author, string $connectionName): string
    {
        $author = self::displayName((string) $author);

        return $author === '' ? self::displayName($connectionName) : self::displayName($author.' · '.$connectionName);
    }

    private static function displayName(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name) ?? ''), 0, 150);
    }

    private function html(string $text): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            .'<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#202124">'
            .'<div style="color:#9aa0a6;font-size:12px;margin:0 0 16px">'.htmlspecialchars(self::SENTINEL, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</div>'
            .$this->markdown->toHtml($text)
            .'</body></html>';
    }
}
