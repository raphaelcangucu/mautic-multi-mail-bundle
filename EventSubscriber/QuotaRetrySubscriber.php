<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\EventSubscriber;

use Mautic\CampaignBundle\Event\FailedEvent;
use MauticPlugin\MauticMultiMailBundle\Mailer\QuotaExceededException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Let Mautic's existing scheduler retain quota-blocked campaign actions, even without a default retry interval. */
final class QuotaRetrySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public static function getSubscribedEvents(): array
    {
        return ['mautic.campaign_on_event_failed' => ['onFailed', -100]];
    }

    public function onFailed(FailedEvent $event): void
    {
        $log = $event->getLog();
        $failed = $log->getFailedLog();
        if (!$failed || !preg_match('/'.preg_quote(QuotaExceededException::MARKER, '/').'([0-9]{10})/', (string) $failed->getReason(), $match)) { return; }
        $now = time();
        $retryAt = max($now + 60, min($now + 35 * 86400, (int) $match[1]));
        $log->setRescheduleInterval(new \DateInterval('PT'.($retryAt - $now).'S'));
        $reason = $this->translator->trans('mautic.multimail.quota.deferred', ['%time%' => gmdate(DATE_ATOM, $retryAt)]);
        $failed->setReason($reason);
        $metadata = $log->getMetadata(); $metadata['reason'] = $reason;
        $log->setMetadata($metadata);
    }
}
