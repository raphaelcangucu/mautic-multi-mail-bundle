<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Address, Email, Message, MessageConverter};
use Symfony\Component\Mime\Part\TextPart;

final class ApiEmail
{
    public static function prepare(Message $message, Envelope $envelope): Email
    {
        $email = clone MessageConverter::toEmail($message);
        // Some bridges compare Address object identity, and add Cc/Bcc outside the envelope.
        // Retain categories only for recipients actually authorized by the native envelope.
        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $category => $addresses) {
            $allowed = array_column(array_map(static fn (Address $address) => ['email' => $address->getAddress()], $addresses), 'email');
            $email->$category(...array_filter($envelope->getRecipients(), static fn (Address $address) => in_array($address->getAddress(), $allowed, true)));
        }
        if ($email->getTextBody() !== null) { $email->text((new TextPart($email->getTextBody()))->getBody()); }
        if ($email->getHtmlBody() !== null) {
            $html = (new TextPart($email->getHtmlBody()))->getBody();
            $replacements = [];
            foreach ($email->getAttachments() as $attachment) {
                if ($attachment->getDisposition() === 'inline' && $attachment->getName() !== null) {
                    $replacements['cid:'.$attachment->getName()] = 'cid:'.$attachment->getContentId();
                }
            }
            $email->html(strtr($html, $replacements));
        }
        return $email;
    }
}
