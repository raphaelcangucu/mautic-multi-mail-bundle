<?php

declare(strict_types=1);

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    // Temporary files, mocked HTTP and synthetic campaign contracts only. Never boot Mautic or open a database.
    require __DIR__.'/bootstrap.php';
}
namespace Mautic\CampaignBundle\Event {
    final class FailedEvent {
        public function __construct(private readonly object $log) {}
        public function getLog(): object { return $this->log; }
    }
}
namespace {
    use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, HourlyQuota, TransportStatus};
    use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, MultiMailTransportFactory, QuotaExceededException};
    use MauticPlugin\MauticMultiMailBundle\EventSubscriber\QuotaRetrySubscriber;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;
    use Symfony\Component\Mailer\Transport\Dsn;
    use Symfony\Component\Mailer\Envelope;
    use Symfony\Component\Mime\{Email, Address};
    use Symfony\Component\Translation\Translator;

    if (($argv[1] ?? '') === 'reserve-child') {
        $child = new ConnectionStore($argv[2]);
        $result = $child->reserve($argv[3], 1);
        if ($result['allowed']) { $child->finishReservation($result['token'], 'accepted'); }
        echo $result['allowed'] ? '1' : '0'; exit;
    }
    $checks = 0;
    function assertQuota(bool $valid, string $reason): void {
        global $checks; ++$checks;
        if (!$valid) { throw new \RuntimeException($reason); }
    }
    function rejectQuota(callable $action): void {
        try { $action(); } catch (\InvalidArgumentException) { assertQuota(true, 'Invalid setting refused'); return; }
        throw new \RuntimeException('Invalid setting accepted');
    }
    function removeQuotaFixture(string $path): void {
        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir() && !$item->isLink()) { removeQuotaFixture($item->getPathname()); }
            else { unlink($item->getPathname()); }
        }
        rmdir($path);
    }
    $root = sys_get_temp_dir().'/multimail_hourly_test_'.bin2hex(random_bytes(8));
    mkdir($root.'/project', 0700, true);
    $input = ['provider' => 'resend', 'name' => 'First sender', 'from_email' => 'first@example.com', 'from_name' => 'First',
        'reply_to' => 'reply@example.com', 'fallback' => '', 'settings' => [], 'secrets' => ['api_key' => 're_unit-private-secret-abcdefghijklmnop'],
        'hourly_limit' => 2, 'priority' => 10, 'pool_enabled' => true, 'quota_group' => ''];
    try {
        $store = new ConnectionStore($root.'/project');
        $view = $store->save($input, 0, 1); $first = $view['connections'][0]['id'];
        $view = $store->save(array_replace($input, ['name' => 'Second sender', 'from_email' => 'second@example.com', 'priority' => 20]), 1, 1);
        $second = $view['connections'][1]['id'];
        $view = $store->save(array_replace($input, ['name' => 'Disabled sender', 'priority' => 0, 'pool_enabled' => false]), 2, 1);
        assertQuota(array_column($store->transportChain('auto'), 'id') === [$first, $second], 'Ordered pool skips excluded sender');
        foreach ([-1, '1.5', [], '1000001'] as $bad) { rejectQuota(fn() => $store->save(array_replace($input, ['hourly_limit' => $bad]), 3, 1)); }
        rejectQuota(fn() => $store->save(array_replace($input, ['quota_group' => '../outside']), 3, 1));
        assertQuota(!str_contains(json_encode($store->overview()), 'unit-private-secret'), 'Usage/history never expose credentials');
        $calls = []; $httpStatus = 200; $body = '{"id":"37e4414c-5e25-4dbc-a071-43552a4bd53b"}';
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$httpStatus, &$body) {
            $calls[] = json_decode($options['body'], true);
            return new MockResponse($body, ['http_code' => $httpStatus]);
        });
        $factory = new MultiMailTransportFactory($store, new ConnectionBuilder($http));
        $rotation = $factory->create(new Dsn('multimail', 'auto'));
        $email = (new Email())->from('original@example.com')->replyTo('original-reply@example.com')->to('recipient@example.com')
            ->subject('Quota unit test')->text('Preserved text')->html('<b>Preserved text</b>')->attach('attachment bytes', 'test.txt', 'text/plain');
        $email->getHeaders()->addTextHeader('X-Test-Tracking', 'preserved');
        $rotation->send($email); $rotation->send($email); $rotation->send($email);
        assertQuota(count($calls) === 3, 'Exhausted sender is skipped before provider handoff');
        assertQuota(str_contains($calls[0]['from'], 'first@example.com') && str_contains($calls[2]['from'], 'second@example.com'), 'Rotation selects the next saved sender');
        assertQuota($calls[2]['reply_to'] === 'reply@example.com' && $calls[2]['headers']['X-Test-Tracking'] === 'preserved' && count($calls[2]['attachments']) === 1, 'Reply-To, tracking and attachments retained');
        assertQuota($email->getFrom()[0]->getAddress() === 'original@example.com', 'Rotation never mutates caller message');
        $view = $store->overview();
        assertQuota($view['connections'][0]['hourly']['accepted'] === 2 && $view['connections'][0]['hourly']['status'] === 'limited', 'Acceptance updates usage and limit badge');
        assertQuota($view['connections'][1]['hourly']['accepted'] === 1 && $view['connections'][1]['hourly']['remaining'] === 1, 'Reserve has its independent remaining capacity');
        $rotation->send($email);
        $callsBefore = count($calls);
        try { $rotation->send($email); throw new \RuntimeException('Expected quota deferral'); }
        catch (QuotaExceededException $e) { assertQuota(str_contains($e->getMessage(), QuotaExceededException::MARKER), 'All capped senders expose scheduler retry signal'); }
        assertQuota(count($calls) === $callsBefore, 'All-capped pool performs no HTTP request');
        $tester = new \MauticPlugin\MauticMultiMailBundle\Application\ConnectionTester($store, new ConnectionBuilder($http));
        assertQuota($tester->send($first, 'recipient@example.com', 3)['status'] === 'quota' && count($calls) === $callsBefore, 'Standalone diagnostic obeys capacity without invoking reserves');
        rejectQuota(fn() => $store->save(array_replace($input, ['id' => $first, 'quota_group' => 'changed']), 3, 1));
        assertQuota($store->overview()['revision'] === 3, 'Charged group change is atomically refused');

        mkdir($root.'/shared-project', 0700);
        $shared = new ConnectionStore($root.'/shared-project');
        $grouped = array_replace($input, ['quota_group' => 'same-provider-account', 'hourly_limit' => 1]);
        $view = $shared->save($grouped, 0, 1); $a = $view['connections'][0]['id'];
        $view = $shared->save(array_replace($grouped, ['name' => 'Second API key', 'hourly_limit' => 0]), 1, 1); $b = $view['connections'][1]['id'];
        $r = $shared->reserve($a, 1); $shared->finishReservation($r['token'], 'accepted');
        assertQuota(!$shared->reserve($b, 1)['allowed'], 'Same account cannot evade shared capacity through another key/adapter');
        assertQuota($shared->overview()['connections'][1]['hourly']['limit'] === 1, 'Strictest positive shared limit applies to unlimited member');
        assertQuota($shared->overview()['connections'][1]['hourly']['used'] === 1 && $shared->overview()['connections'][1]['hourly']['accepted'] === 0, 'Shared quota and per-connection acceptance are distinguished');
        rejectQuota(fn() => $shared->save(array_replace($grouped, ['id' => $b, 'name' => 'Second API key', 'quota_group' => '']), 2, 1));

        mkdir($root.'/clock-project', 0700);
        $now = 1791414001;
        $clock = new HourlyQuota($root.'/clock-project', static function () use (&$now): int { return $now; });
        $connection = $input + ['id' => str_repeat('a', 32)];
        $r = $clock->reserve($connection, 2, 2);
        assertQuota(!$clock->reserve($connection, 1, 2)['allowed'], 'All envelope recipients reserve capacity atomically');
        $now += 90; $clock->finish($r['token'], 'accepted');
        assertQuota($clock->snapshot([$connection])[$connection['id']]['accepted'] === 2, 'Accepted handoff uses completion time');
        $clock->finish($r['token'], 'rejected');
        assertQuota(!$clock->reserve($connection, 1, 2)['allowed'], 'Double finalization cannot release accepted capacity');
        $acceptedAt = $now;
        $now = $acceptedAt + 3599;
        assertQuota(!$clock->reserve($connection, 1, 2)['allowed'], 'Quota cannot reset at a wall-clock hour boundary');
        $now = (int) (floor($acceptedAt / 60) + 1) * 60 + 3600;
        $r = $clock->reserve($connection, 2, 2); assertQuota($r['allowed'], 'Expired rolling capacity becomes available');
        $clock->finish($r['token'], 'rejected');
        $r = $clock->reserve($connection, 2, 2); assertQuota($r['allowed'], 'Confirmed refusal releases reservation');
        $clock->finish($r['token'], 'uncertain');
        assertQuota(!$clock->reserve($connection, 1, 2)['allowed'], 'Uncertain handoff retains capacity');
        $now += 3700; $r = $clock->reserve($connection, 2, 2); $now += 3700;
        assertQuota(!$clock->reserve($connection, 1, 2)['allowed'], 'Unfinished reservation remains conservative after the ordinary hour window');
        $clock->finish($r['token'], 'rejected');
        assertQuota($clock->reserve($connection, 1, 2)['allowed'], 'Confirmed non-acceptance reconciles a pending reservation');

        mkdir($root.'/concurrent-project', 0700);
        $concurrent = new ConnectionStore($root.'/concurrent-project');
        $view = $concurrent->save(array_replace($input, ['hourly_limit' => 7]), 0, 1); $id = $view['connections'][0]['id'];
        $children = [];
        for ($n = 0; $n < 20; ++$n) {
            $pipes = []; $process = proc_open([PHP_BINARY, __FILE__, 'reserve-child', $root.'/concurrent-project', $id], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
            fclose($pipes[0]); $children[] = [$process, $pipes];
        }
        $accepted = 0;
        foreach ($children as [$process, $pipes]) {
            $answer = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            assertQuota(proc_close($process) === 0 && $error === '', 'Concurrent reservation process exits cleanly');
            $accepted += (int) $answer;
        }
        assertQuota($accepted === 7 && $concurrent->overview()['connections'][0]['hourly']['accepted'] === 7, 'Twenty PHP workers cannot exceed seven reserved recipients');

        $failed = new class { public string $reason; public function getReason(): string { return $this->reason; } public function setReason(string $reason): void { $this->reason=$reason; } };
        $failed->reason = QuotaExceededException::MARKER.(time()+120);
        $log = new class($failed) {
            public ?\DateInterval $interval=null; public array $metadata=['failed'=>1,'preserved'=>true];
            public function __construct(private object $failed) {}
            public function getFailedLog(): object { return $this->failed; }
            public function setRescheduleInterval(\DateInterval $interval): void { $this->interval=$interval; }
            public function getMetadata(): array { return $this->metadata; }
            public function setMetadata(array $metadata): void { $this->metadata=$metadata; }
        };
        $subscriber = new QuotaRetrySubscriber(new Translator('en_US'));
        $subscriber->onFailed(new \Mautic\CampaignBundle\Event\FailedEvent($log));
        assertQuota($log->interval?->s >= 119 && $log->metadata['preserved'] && !str_contains($failed->reason, QuotaExceededException::MARKER), 'Quota campaign failure receives a retry interval without kernel/database or leaking its internal marker');
        $log->interval = null; $failed->reason='Ordinary transport failure';
        $subscriber->onFailed(new \Mautic\CampaignBundle\Event\FailedEvent($log));
        assertQuota($log->interval === null, 'Other campaign failures retain native policy');
        assertQuota(TransportStatus::describe('multimail://auto', [])['rotation'] === true, 'Global rotation is accurately identified');
        assertQuota((fileperms($root.'/project-multimail-private/hourly-usage.json') & 0777) === 0600, 'Quota history remains private');
        $path = $root.'/concurrent-project-multimail-private/hourly-usage.json';
        file_put_contents($path, '{broken');
        try { $concurrent->reserve($id, 1); throw new \LogicException('Corrupt ledger accepted'); }
        catch (\RuntimeException) { assertQuota(true, 'Corrupt ledger fails closed'); }
    } finally { removeQuotaFixture($root); }
    echo 'PASS: '.$checks." hourly quota, account grouping, rolling expiry, recipient accounting, rotation, diagnostics, ambiguity, campaign retry and real concurrent-file checks; no kernel/database/network\n";
}
