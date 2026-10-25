<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};
use Twig\{Environment, TwigFunction, TwigFilter};

$loader = new FilesystemLoader(__DIR__.'/../Resources/views');
$loader->addPath(__DIR__.'/../Resources/views', 'MauticMultiMail');
$twig = new Environment(new ChainLoader([new ArrayLoader(['@MauticCore/Default/content.html.twig' => '{% block content %}{% endblock %}']), $loader]), ['strict_variables' => true]);
$twig->addFunction(new TwigFunction('path', fn(string $route, array $parameters = []): string => '/s/mail-connections?'.http_build_query($parameters)));
$twig->addFunction(new TwigFunction('csrf_token', fn(string $id): string => 'isolated-csrf'));
$twig->addFunction(new TwigFunction('includeScript', fn(...$arguments): string => '', ['is_safe' => ['html']]));
$twig->addFilter(new TwigFilter('trans', fn(string $key): string => $key));
$base = ['data' => ['revision' => 0, 'connections' => []], 'editing' => null, 'provider' => 'smtp',
    'providers' => ConnectionStore::PROVIDERS, 'error' => null, 'saved' => false,
    'transport' => ['connection_id' => null, 'name' => null, 'scheme' => 'smtp'], 'testId' => '', 'testRecipient' => 'operator@example.com', 'lastTest' => null,
    'conversationAvailable' => false, 'inbound' => [], 'monitoredAddress' => ''];
$root=sys_get_temp_dir().'/multimail_template_test_'.bin2hex(random_bytes(8)); mkdir($root.'/project',0700,true);
try {
    $empty = $twig->render('Connections/index.html.twig', $base);
    if (!str_contains($empty, 'mail-usage-refresh') || !str_contains($empty, 'name="hourly_limit"')) { throw new RuntimeException('Empty registry template missing controls'); }
    $store = new ConnectionStore($root.'/project');
    $data = $store->save(['provider' => 'resend', 'name' => '<script>unsafe</script>', 'from_email' => 'sender@example.com', 'from_name' => 'Sender',
        'reply_to' => '', 'fallback' => '', 'settings' => [], 'secrets' => ['api_key' => 're_private-unit-secret-abcdefghijklmnop'],
        'hourly_limit' => 1000, 'daily_limit' => 100, 'monthly_limit' => 3000, 'priority' => 0, 'quota_group' => 'resend-account'], 0, 1);
    $html=$twig->render('Connections/index.html.twig', array_replace($base, ['data'=>$data,'editing'=>$data['connections'][0],'provider'=>'resend',
        'transport'=>['connection_id'=>null,'name'=>null,'scheme'=>'multimail','rotation'=>true]]));
    if (str_contains($html, '<script>unsafe</script>') || str_contains($html,'private-unit-secret') || !str_contains($html,'value="1000"')
        || !preg_match('/id="mail-priority"[^>]*value="0"/', $html)
        || !preg_match('/id="mail-daily-limit"[^>]*value="100"/', $html) || !preg_match('/id="mail-monthly-limit"[^>]*value="3000"/', $html)
        || substr_count($html, 'data-quota-period=') !== 3) { throw new RuntimeException('Quota template escaping/value regression'); }
    if (!preg_match('/id="mail-conversational"[^>]*data-mail-locked="1"[^>]*disabled/', $empty) || !str_contains($empty, 'mail-conversation-unavailable')) {
        throw new RuntimeException('Without the Meta bundle the conversational flag is locked with an explanation');
    }
    // Conversational SMTP connection with the Meta bundle present, a delayed mailbox and a Monitored Inbox clash.
    $data = $store->save(['provider' => 'smtp', 'name' => 'Contato', 'from_email' => 'Contato@macro.test', 'from_name' => 'Macro', 'reply_to' => '', 'fallback' => '',
        'conversational' => '1', 'settings' => ['host' => 'smtp.macro.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'contato@macro.test',
        'imap_host' => 'imap.macro.test', 'imap_port' => '143', 'imap_username' => '', 'imap_folder' => 'INBOX', 'imap_sent_folder' => 'Enviados'],
        'secrets' => ['password' => 'smtp-unit-secret', 'imap_password' => 'imap-unit-secret']], $data['revision'], 1);
    $smtp = $data['connections'][1];
    $html = $twig->render('Connections/index.html.twig', array_replace($base, ['data' => $data, 'editing' => $smtp, 'provider' => 'smtp', 'testId' => $smtp['id'],
        'conversationAvailable' => true, 'monitoredAddress' => 'contato@macro.test',
        'inbound' => [$smtp['id'] => ['status' => 'delayed', 'auth_failed' => false, 'last_success_at' => null, 'last_error' => 'IMAP server timed out.', 'problem_since' => '2026-10-24T10:00:00+00:00']]]));
    foreach (['name="conversational" value="1" id="mail-conversational"' => 'flag', 'name="settings[imap_host]" id="mail-imap-host" value="imap.macro.test"' => 'host', '<option value="143" selected>' => 'port',
        'id="mail-imap-sent" value="Enviados"' => 'sent folder', 'data-mail-inbound="delayed"' => 'delayed badge', 'mail-monitored-conflict' => 'Monitored Inbox warning',
        'data-imap="1"' => 'round-trip option', 'id="mail-roundtrip"' => 'round-trip button', 'mautic.multimail.conversation.plus_unchecked' => '+tag status'] as $needle => $what) {
        if (!str_contains($html, $needle)) { throw new RuntimeException('Conversational template: '.$what); }
    }
    if (!preg_match('/id="mail-conversational"[^>]*checked/', $html) || str_contains($html, 'data-mail-locked') || str_contains($html, 'unit-secret') || str_contains($html, 'mail-conversation-unavailable')) {
        throw new RuntimeException('Conversational template: checked, unlocked and without secrets');
    }
    echo "PASS: empty/populated/rotation/conversational Twig templates render with strict variables, quota values, zero priority, escaped names, locked flag without Meta, IMAP fields, inbound badge and Monitored Inbox warning; no kernel/database/network\n";
} finally {
    foreach (glob($root.'/project-multimail-private/*') as $file) { unlink($file); }
    rmdir($root.'/project-multimail-private'); rmdir($root.'/project'); rmdir($root);
}
