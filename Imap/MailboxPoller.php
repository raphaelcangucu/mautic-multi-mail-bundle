<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Imap;

use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, PrivateStorage};
use MauticPlugin\MauticMultiMailBundle\Conversation\{ConversationBridgeInterface, InboundParser};
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One pass over every conversational mailbox (cron, every minute).
 *
 * - one flock per connection: two passes never read the same mailbox;
 * - read-only (EXAMINE + BODY.PEEK[]), in batches of 50;
 * - `last_uid` advances only after the message is stored, so a crash re-reads and the
 *   scoped external id drops the duplicate;
 * - the first pass starts at UIDNEXT (no history import); a new UIDVALIDITY re-reads 7 days;
 * - failures back off 1, 2, 4… up to 30 minutes; 30 minutes without success is "delayed";
 *   a refused password stops polling until the connection is saved again.
 */
final class MailboxPoller
{
    public const BATCH = 50;
    public const MAX_PER_PASS = 500;
    public const BODY_GROUP_BYTES = 33554432;
    public const DELAYED_AFTER = 1800;
    public const MAX_BACKOFF = 1800;
    public const RESYNC_DAYS = 7;

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly ConversationBridgeInterface $bridge,
        private readonly ImapStateStore $states,
        private readonly InboundParser $parser,
        private readonly ImapConnectorInterface $connector,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ?InboundAlerts $alerts = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return array<string, array{status: string, stored?: int, outcomes?: array<string, int>, error?: string}> */
    public function poll(?string $onlyId = null, ?int $now = null, int $budgetSeconds = 45): array
    {
        if (!$this->bridge->available()) { return []; }
        $now ??= time();
        $deadline = microtime(true) + $budgetSeconds;
        $all = $this->store->overview()['connections'];
        try { $this->bridge->synchronize($all); }
        catch (\Throwable $exception) { $this->logger?->error('multimail: email asset synchronization failed', ['exception' => $exception::class]); }
        $this->states->prune(array_column($all, 'id'));
        $results = [];
        foreach ($this->store->conversationalConnections() as $connection) {
            if ($onlyId !== null && $connection['id'] !== $onlyId) { continue; }
            $results[$connection['id']] = $this->pollConnection($connection, $now, $deadline);
        }

        return $results;
    }

    private function pollConnection(array $connection, int $now, float $deadline): array
    {
        $id = $connection['id'];
        $lock = PrivateStorage::tryLock($this->projectDir, 'imap-poll-'.$id);
        if ($lock === null) { return ['status' => 'locked']; }
        $client = null;
        try {
            $state = $this->states->get($id);
            $settings = $connection['settings'];
            $position = hash('sha256', implode("\0", [$settings['imap_host'], $settings['imap_port'], $settings['imap_username'], $settings['imap_folder']]));
            if ($state['position'] !== $position) {
                // Another mailbox or folder: its UIDs mean nothing here. Start fresh at UIDNEXT.
                $state = $this->states->update($id, ['position' => $position, 'uidvalidity' => null, 'last_uid' => 0, 'failures' => 0,
                    'next_attempt_at' => null, 'auth_failed' => false, 'auth_stamp' => null]);
            }
            if ($state['auth_failed'] && $state['auth_stamp'] === $connection['updated_at']) { return ['status' => 'auth_failed']; }
            if ($state['next_attempt_at'] !== null && strtotime($state['next_attempt_at']) > $now) { return ['status' => 'waiting']; }
            try {
                $client = $this->connector->connect($connection);
                $result = $this->read($client, $connection, $state, $now, $deadline);
                $this->confirmSent($client, $connection);
                $this->states->update($id, ['last_success_at' => gmdate(DATE_ATOM, $now), 'last_error' => null, 'failures' => 0, 'next_attempt_at' => null,
                    'problem_since' => null, 'status' => 'ok', 'auth_failed' => false, 'auth_stamp' => null, 'notified' => null]);
                $this->status($connection, 'ok', null);

                return $result;
            } catch (ImapAuthenticationFailed) {
                $since = $state['problem_since'] ?? gmdate(DATE_ATOM, $now);
                $this->states->update($id, ['auth_failed' => true, 'auth_stamp' => $connection['updated_at'], 'status' => 'auth_failed',
                    'problem_since' => $since, 'last_error' => 'IMAP authentication failed.']);
                $this->status($connection, 'auth_failed', $since);
                $this->alert($connection, 'auth_failed', $state);

                return ['status' => 'auth_failed'];
            } catch (\Throwable $exception) {
                $failures = $state['failures'] + 1;
                $since = $state['problem_since'] ?? gmdate(DATE_ATOM, $now);
                $reference = max(strtotime($since), $state['last_success_at'] !== null ? strtotime($state['last_success_at']) : 0);
                $status = $now - $reference >= self::DELAYED_AFTER ? 'delayed' : 'ok';
                $error = self::safeError($exception);
                $this->states->update($id, ['failures' => $failures, 'problem_since' => $since, 'status' => $status, 'last_error' => $error,
                    'next_attempt_at' => gmdate(DATE_ATOM, $now + min(self::MAX_BACKOFF, 60 * 2 ** min(10, $failures - 1)))]);
                $this->logger?->warning('multimail: IMAP poll failed', ['connection' => $id, 'error' => $error, 'failures' => $failures]);
                if ($status === 'delayed') {
                    $this->status($connection, 'delayed', $since);
                    $this->alert($connection, 'delayed', $state);
                }

                return ['status' => 'error', 'error' => $error];
            }
        } finally {
            $client?->logout();
            PrivateStorage::release($lock);
        }
    }

    private function read(ImapClient $client, array $connection, array $state, int $now, float $deadline): array
    {
        $id = $connection['id'];
        $folder = $client->examine($connection['settings']['imap_folder']);
        if ($state['uidvalidity'] === null) {
            // First pass: start with the next message that arrives, never import the history.
            $this->states->update($id, ['uidvalidity' => $folder['uidvalidity'], 'last_uid' => max(0, $folder['uidnext'] - 1)]);
            return ['status' => 'initialized', 'stored' => 0, 'outcomes' => []];
        }
        if ($state['uidvalidity'] !== $folder['uidvalidity']) {
            // The server renumbered the folder: re-read a week; the external ids drop what is already stored.
            $uids = $client->uidsSince((new \DateTimeImmutable('@'.$now))->modify('-'.self::RESYNC_DAYS.' days'));
            $this->states->update($id, ['uidvalidity' => $folder['uidvalidity'], 'last_uid' => 0]);
            $last = 0;
        } else {
            $last = (int) $state['last_uid'];
            $uids = $client->uidsAfter($last);
        }
        $uids = array_slice(array_values(array_filter($uids, fn(int $uid): bool => $uid > $last)), 0, self::MAX_PER_PASS);
        $outcomes = [];
        $stored = 0;
        foreach (array_chunk($uids, self::BATCH) as $batch) {
            if (microtime(true) > $deadline) { break; }
            $summaries = $client->fetch($batch, 'summary');
            foreach ($this->groups($batch, $summaries) as [$section, $group]) {
                $messages = $client->fetch($group, $section);
                foreach ($group as $uid) {
                    $raw = $messages[$uid]['raw'] ?? null;
                    if ($raw !== null) {
                        $receivedAt = $summaries[$uid]['internaldate'] ?? $messages[$uid]['internaldate'] ?? new \DateTimeImmutable('@'.$now);
                        try { $email = $this->parser->parse($raw, $section === 'header'); }
                        catch (\Throwable) { $email = null; }
                        // A message that does not parse becomes an event; it never blocks the mailbox.
                        $outcome = $email === null ? $this->bridge->ingestUnreadable($connection, $raw, $receivedAt)
                            : $this->bridge->ingest($connection, $email, $raw, $receivedAt);
                        $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                        ++$stored;
                    }
                    // Only now, after the write: a crash before this line re-reads the message.
                    $this->states->update($id, ['last_uid' => $uid]);
                }
                if (microtime(true) > $deadline) { break 2; }
            }
        }

        return ['status' => 'ok', 'stored' => $stored, 'outcomes' => $outcomes];
    }

    /** Consecutive bodies fetched together up to 32 MiB; oversized messages as headers only. */
    private function groups(array $batch, array $summaries): array
    {
        $groups = [];
        $current = [];
        $bytes = 0;
        foreach ($batch as $uid) {
            $size = $summaries[$uid]['size'] ?? 0;
            if (!isset($summaries[$uid])) { $groups[] = ['summary', [$uid]]; continue; } // Expunged meanwhile.
            if ($size > ImapClient::MAX_MESSAGE_BYTES) {
                if ($current !== []) { $groups[] = ['body', $current]; $current = []; $bytes = 0; }
                $groups[] = ['header', [$uid]];
                continue;
            }
            if ($current !== [] && $bytes + $size > self::BODY_GROUP_BYTES) { $groups[] = ['body', $current]; $current = []; $bytes = 0; }
            $current[] = $uid;
            $bytes += $size;
        }
        if ($current !== []) { $groups[] = ['body', $current]; }

        return $groups;
    }

    /** A timeout after DATA may still have sent the message: Gmail and Microsoft 365 keep a copy in Sent. */
    private function confirmSent(ImapClient $client, array $connection): void
    {
        $folder = $connection['settings']['imap_sent_folder'] ?? '';
        if ($folder === '') { return; }
        try {
            $ids = $this->bridge->uncertainMessageIds($connection);
            if ($ids === []) { return; }
            $client->examine($folder);
            foreach ($ids as $messageId) {
                if ($client->uidsByMessageId($messageId) !== []) { $this->bridge->confirmSent($connection, $messageId); }
            }
        } catch (ImapException $exception) {
            // The inbox was read; a missing Sent folder must not mark the mailbox as failing.
            $this->logger?->warning('multimail: Sent folder check failed', ['connection' => $connection['id'], 'error' => self::safeError($exception)]);
        }
    }

    private function status(array $connection, string $status, ?string $since): void
    {
        try { $this->bridge->inboundStatus($connection, $status, $since); }
        catch (\Throwable $exception) { $this->logger?->error('multimail: inbound status update failed', ['exception' => $exception::class]); }
    }

    private function alert(array $connection, string $status, array $state): void
    {
        if ($state['notified'] === $status) { return; }
        try {
            $this->alerts?->notify($connection, $status);
            $this->states->update($connection['id'], ['notified' => $status]);
        } catch (\Throwable $exception) {
            $this->logger?->error('multimail: administrator alert failed', ['exception' => $exception::class]);
        }
    }

    private static function safeError(\Throwable $exception): string
    {
        // IMAP errors are built without credentials; anything else is reduced to its class.
        $message = $exception instanceof ImapException ? $exception->getMessage() : $exception::class;

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '', 0, 300);
    }
}
