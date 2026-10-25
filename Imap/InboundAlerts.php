<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\UserBundle\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/** One Mautic notification per administrator when a mailbox stops being read. */
final class InboundAlerts
{
    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?NotificationModel $notifications = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function notify(array $connection, string $status): void
    {
        if ($this->entityManager === null || $this->notifications === null) { return; }
        $key = $status === 'auth_failed' ? 'mautic.multimail.conversation.alert_auth' : 'mautic.multimail.conversation.alert_delayed';
        $name = htmlspecialchars((string) $connection['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = $this->translator?->trans($key, ['%name%' => $name]) ?? $key;
        $header = $this->translator?->trans('mautic.multimail.conversation.alert_header') ?? 'Multi Mail';
        $admins = $this->entityManager->createQuery('SELECT u FROM '.User::class.' u JOIN u.role r WHERE r.isAdmin = 1 AND u.isPublished = 1')->getResult();
        foreach ($admins as $admin) {
            $this->notifications->addNotification($message, 'warning', false, $header, 'ri-mail-close-line', null, $admin,
                'multimail-imap-'.$connection['id'].'-'.$status, new \DateTime('-1 day'));
        }
    }
}
