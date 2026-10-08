<?php

declare(strict_types=1);

// Files and mock HTTP only: no Mautic kernel, database or live provider calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\{ConnectionStore, HourlyQuota, ConnectionTester};
use MauticPlugin\MauticMultiMailBundle\Mailer\{ConnectionBuilder, MultiMailTransportFactory, QuotaExceededException};
use Symfony\Component\HttpClient\{MockHttpClient};
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

if (($argv[1] ?? '') === 'child') {
    $store = new ConnectionStore($argv[2]); $r = $store->reserve($argv[3], 1);
    if ($r['allowed']) { $store->finishReservation($r['token'], 'accepted'); }
    echo $r['allowed'] ? '1' : '0'; exit;
}
$checks = 0;
function checkPeriod(bool $ok, string $reason): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($reason); } }
function cleanPeriod(string $path): void {
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) { cleanPeriod($item->getPathname()); } else { unlink($item->getPathname()); }
    } rmdir($path);
}
$root = sys_get_temp_dir().'/multimail_period_test_'.bin2hex(random_bytes(8)); mkdir($root, 0700);
$input = ['provider'=>'resend','name'=>'Primary','from_email'=>'primary@example.com','from_name'=>'Primary','reply_to'=>'',
    'fallback'=>'','settings'=>[],'secrets'=>['api_key'=>'re_unit-private-secret-abcdefghijklmnop'],
    'hourly_limit'=>0,'daily_limit'=>2,'monthly_limit'=>3,'priority'=>10,'pool_enabled'=>true,'quota_group'=>''];
try {
    mkdir($root.'/clock',0700); $now = strtotime('2028-02-28T23:59:30Z');
    $q = new HourlyQuota($root.'/clock', static function () use (&$now): int { return $now; });
    $c = $input + ['id'=>str_repeat('a',32)];
    $snapshot = function () use (&$q, $c): array { return $q->snapshot([$c])[$c['id']]['periods']; };
    $r = $q->reserve($c,2,0,2,3); checkPeriod($r['allowed'],'Multi-recipient daily reservation allowed');
    checkPeriod(!$q->reserve($c,1,0,2,3)['allowed'],'Pending recipients consume daily capacity');
    $now = strtotime('2028-02-29T00:00:10Z');
    checkPeriod(!$q->reserve($c,1,0,2,3)['allowed'],'Pending handoff crosses UTC midnight without evading new day cap');
    $q->finish($r['token'],'accepted'); $q->finish($r['token'],'rejected');
    checkPeriod($snapshot()['daily']['used']===2 && $snapshot()['monthly']['used']===2,'Completion charged once to the new UTC day');
    $now = strtotime('2028-03-01T00:00:10Z');
    checkPeriod($snapshot()['daily']['used']===0 && $snapshot()['monthly']['used']===0,'Leap-year month resets at UTC day 1');
    $r=$q->reserve($c,2,0,2,3); $q->finish($r['token'],'rejected');
    checkPeriod($snapshot()['daily']['used']===0 && $snapshot()['monthly']['used']===0,'Confirmed refusal releases every period');
    $r=$q->reserve($c,2,0,2,3); $q->finish($r['token'],'uncertain');
    $now += 2*86400;
    checkPeriod($snapshot()['hourly']['used']===0 && $snapshot()['daily']['used']===0 && $snapshot()['monthly']['used']===2,'Monthly accounting survives 24-hour minute history pruning');
    $r=$q->reserve($c,1,0,2,3); $q->finish($r['token'],'accepted');
    $denied=$q->reserve($c,1,0,2,3);
    checkPeriod(!$denied['allowed'] && $denied['blocked_by']===['monthly'] && $denied['retry_at']==='2028-04-01T00:00:00+00:00','Monthly cap supplies calendar reset retry');
    $q->block($c,'daily');
    $denied=$q->reserve($c,1,0,0,3);
    checkPeriod($denied['blocked_by']===['daily','monthly'] && $denied['retry_at']==='2028-04-01T00:00:00+00:00','Retry waits for all unavailable periods on the account');
    $now = strtotime('2028-04-01T00:00:00Z');
    checkPeriod($q->reserve($c,1,0,2,3)['allowed'],'Expired provider blocks and calendar quotas free capacity');
    checkPeriod(HourlyQuota::resetAt('monthly',strtotime('2026-01-31T23:59:59Z'))===strtotime('2026-02-01T00:00:00Z'),'January 31 resolves to February 1');
    checkPeriod(HourlyQuota::resetAt('monthly',strtotime('2026-12-31T23:59:59Z'))===strtotime('2027-01-01T00:00:00Z'),'December resolves to new year');

    mkdir($root.'/baseline',0700); $now=strtotime('2026-10-08T12:00:00Z');
    $q = new HourlyQuota($root.'/baseline',static function () use (&$now):int{return $now;});
    $q->importPeriodUsage($c,2,10,'actual-dashboard');
    $r=$q->reserve($c,1,0,100,3000);
    $q->importPeriodUsage($c,3,11,'new-dashboard');
    checkPeriod($snapshot()['daily']['used']===4 && $snapshot()['monthly']['used']===12,'Baseline keeps outstanding reservations in addition to observed acceptance');
    $q->finish($r['token'],'rejected');
    $q->importPeriodUsage($c,99,999,'actual-dashboard');
    $q->importPeriodUsage($c,0,0,'lower-dashboard');
    checkPeriod($snapshot()['daily']['used']===3 && $snapshot()['monthly']['used']===11,'Imports are idempotent and never lower charged capacity');

    mkdir($root.'/upgrade',0700); $q=new HourlyQuota($root.'/upgrade',static fn():int=>$now);
    $r=$q->reserve($c,1,0,2,3); $q->finish($r['token'],'accepted');
    $path=$root.'/upgrade-multimail-private/hourly-usage.json'; $ledger=json_decode(file_get_contents($path),true); unset($ledger['periods'],$ledger['blocks']); file_put_contents($path,json_encode($ledger));
    $before=$q->snapshot([$c])[$c['id']]['periods'];
    $r=$q->reserve($c,1,0,2,3);$q->finish($r['token'],'accepted');
    $after=(new HourlyQuota($root.'/upgrade',static fn():int=>$now))->snapshot([$c])[$c['id']]['periods'];
    checkPeriod($before['monthly']['used']===1 && $after['monthly']['used']===2,'Old hourly ledger upgrades without losing or doubling usage');
    $ledger=json_decode(file_get_contents($path),true);$ledger['periods']['connection:'.$c['id']]['monthly']['2026-10']=-1;file_put_contents($path,json_encode($ledger));
    try { $q->reserve($c,1,0,2,3); throw new LogicException('Corrupt period ledger accepted'); } catch (RuntimeException) { checkPeriod(true,'Corrupt period ledger fails closed'); }

    mkdir($root.'/rotation',0700); $store=new ConnectionStore($root.'/rotation');
    $view=$store->save(array_replace($input,['daily_limit'=>1,'monthly_limit'=>0]),0,1);$first=$view['connections'][0]['id'];
    $view=$store->save(array_replace($input,['name'=>'Reserve','priority'=>20,'from_email'=>'reserve@example.com','daily_limit'=>0,'monthly_limit'=>1]),1,1);$second=$view['connections'][1]['id'];
    $calls=[];$status=200;$body='{"id":"37e4414c-5e25-4dbc-a071-43552a4bd53b"}';
    $http=new MockHttpClient(function($method,$url,$options) use (&$calls,&$status,&$body){$calls[]=json_decode($options['body'],true);return new MockResponse($body,['http_code'=>$status]);});
    $builder=new ConnectionBuilder($http); $factory=new MultiMailTransportFactory($store,$builder);$rotation=$factory->create(new Dsn('multimail','auto'));
    $email=(new Email())->from('original@example.com')->to('recipient@example.com')->subject('Unit test')->text('Preserve');
    $rotation->send($email);$rotation->send($email);
    checkPeriod(count($calls)===2 && str_contains($calls[1]['from'],'reserve@example.com'),'Daily cap skips primary before handoff');
    try {$rotation->send($email);throw new LogicException('All-period cap accepted');}catch(QuotaExceededException){checkPeriod(count($calls)===2,'Monthly-capped reserve defers without network');}
    foreach(['daily_limit','monthly_limit'] as $field){
        foreach([-1, '1.5', [], 1000001] as $bad){try{$store->save(array_replace($input,[$field=>$bad]),2,1);throw new LogicException('Invalid cap accepted');}catch(InvalidArgumentException){checkPeriod(true,'Invalid cap refused');}}
    }
    try{$store->save(array_replace($input,['provider'=>'native','secrets'=>['dsn'=>'null://null'],'pool_enabled'=>false]),2,1);throw new LogicException('Native period cap accepted');}catch(InvalidArgumentException){checkPeriod(true,'Native retains its quota controls');}

    mkdir($root.'/provider',0700);$store=new ConnectionStore($root.'/provider');
    $group=array_replace($input,['quota_group'=>'provider-account','daily_limit'=>0,'monthly_limit'=>0]);
    $view=$store->save($group,0,1);$first=$view['connections'][0]['id'];
    $view=$store->save(array_replace($group,['name'=>'Shared key','priority'=>20]),1,1);$second=$view['connections'][1]['id'];
    $view=$store->save(array_replace($group,['name'=>'Other account','quota_group'=>'other','priority'=>30]),2,1);$third=$view['connections'][2]['id'];
    $rotation=(new MultiMailTransportFactory($store,$builder))->create(new Dsn('multimail','auto'));
    $calls=[];$number=0;
    $http2=new MockHttpClient(function()use(&$number){++$number;return $number===1 ? new MockResponse('{"name":"daily_quota_exceeded"}',['http_code'=>429]) : new MockResponse('{"id":"37e4414c-5e25-4dbc-a071-43552a4bd53b"}',['http_code'=>200]);});
    $rotation=(new MultiMailTransportFactory($store,new ConnectionBuilder($http2)))->create(new Dsn('multimail','auto'));$rotation->send($email);
    $view=$store->overview();
    checkPeriod($number===2 && $view['connections'][1]['hourly']['accepted']===0 && $view['connections'][2]['hourly']['accepted']===1,'Remote daily refusal blocks another key and rotates to another account');
    checkPeriod($view['connections'][0]['hourly']['periods']['daily']['provider_blocked'] && $view['connections'][1]['hourly']['status']==='limited','Remote block is shared even without local positive cap');
    try{$store->save(array_replace($group,['id'=>$second,'quota_group'=>'changed']),3,1);throw new LogicException('Provider block evaded');}catch(InvalidArgumentException){checkPeriod(true,'Blocked account cannot change group');}
    $http3=new MockHttpClient(new MockResponse('{"name":"monthly_quota_exceeded"}',['http_code'=>429]));
    $diagnostic=(new ConnectionTester($store,new ConnectionBuilder($http3)))->send($third,'recipient@example.com',3);
    checkPeriod($diagnostic['status']==='quota' && $diagnostic['error_code']==='monthly_quota_exceeded' && $store->overview()['connections'][2]['hourly']['periods']['monthly']['provider_blocked'],'Diagnostic persists confirmed monthly block without raw error');
    try{$rotation->send($email);throw new LogicException('Remote quota pool accepted');}catch(QuotaExceededException $e){checkPeriod(str_contains($e->getMessage(),QuotaExceededException::MARKER),'Remote capped pool uses native scheduler signal');}

    foreach(['daily','monthly']as$period){
        mkdir($root.'/'.$period,0700);$store=new ConnectionStore($root.'/'.$period);
        $view=$store->save(array_replace($input,['daily_limit'=>0,'monthly_limit'=>0,$period.'_limit'=>7]),0,1);$id=$view['connections'][0]['id'];$children=[];
        for($i=0;$i<20;++$i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'child',$root.'/'.$period,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
        $accepted=0;foreach($children as[$p,$pipes]){$answer=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);checkPeriod(proc_close($p)===0&&$error==='','Concurrent process clean');$accepted+=(int)$answer;}
        checkPeriod($accepted===7&&$store->overview()['connections'][0]['hourly']['periods'][$period]['used']===7,'Twenty workers respect '.$period.' cap with unlimited hourly cap');
    }
    $groupA=$c+[];$groupA['quota_group']='shared';$groupA['daily_limit']=5;$groupA['monthly_limit']=10;
    $groupB=array_replace($groupA,['daily_limit'=>3,'monthly_limit'=>0]);
    checkPeriod(HourlyQuota::effectiveLimit($groupA,[$groupA,$groupB],'daily_limit')===3&&HourlyQuota::effectiveLimit($groupB,[$groupA,$groupB],'monthly_limit')===10,'Strictest positive shared cap applies independently per period');
}finally{cleanPeriod($root);}
echo "PASS: $checks daily/monthly quota, UTC boundary, legacy upgrade, remote refusal, accounting and concurrent-file checks; no kernel/database/network\n";
