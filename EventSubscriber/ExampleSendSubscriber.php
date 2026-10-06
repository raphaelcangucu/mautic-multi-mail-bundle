<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\MauticMultiMailBundle\Application\ExampleRouting;
use MauticPlugin\MauticMultiMailBundle\Mailer\ExampleTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ExampleSendSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ExampleRouting $routing, private readonly UserHelper $users) {}
    public static function getSubscribedEvents(): array { return [EmailEvents::EMAIL_PRE_SEND => ['onPreSend', -1024]]; }
    public function onPreSend(EmailSendEvent $event): void
    {
        $id = $this->routing->selected();
        if ($id === null || !$this->routing->appliesToEmail($event->getEmail()?->getId())
            || !$this->users->getUser(true)?->isAdmin()) { return; }
        $event->addTextHeader('X-Transport', ExampleTransport::NAME);
        $event->addTextHeader(ExampleTransport::HEADER, $id);
    }
}
