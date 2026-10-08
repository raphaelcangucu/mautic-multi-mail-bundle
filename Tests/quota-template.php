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
    'transport' => ['connection_id' => null, 'name' => null, 'scheme' => 'smtp'], 'testId' => '', 'testRecipient' => 'operator@example.com', 'lastTest' => null];
$root=sys_get_temp_dir().'/multimail_template_test_'.bin2hex(random_bytes(8)); mkdir($root.'/project',0700,true);
try {
    $empty = $twig->render('Connections/index.html.twig', $base);
    if (!str_contains($empty, 'mail-usage-refresh') || !str_contains($empty, 'name="hourly_limit"')) { throw new RuntimeException('Empty registry template missing controls'); }
    $store = new ConnectionStore($root.'/project');
    $data = $store->save(['provider' => 'resend', 'name' => '<script>unsafe</script>', 'from_email' => 'sender@example.com', 'from_name' => 'Sender',
        'reply_to' => '', 'fallback' => '', 'settings' => [], 'secrets' => ['api_key' => 're_private-unit-secret-abcdefghijklmnop'],
        'hourly_limit' => 1000, 'priority' => 0, 'quota_group' => 'resend-account'], 0, 1);
    $html=$twig->render('Connections/index.html.twig', array_replace($base, ['data'=>$data,'editing'=>$data['connections'][0],'provider'=>'resend',
        'transport'=>['connection_id'=>null,'name'=>null,'scheme'=>'multimail','rotation'=>true]]));
    if (str_contains($html, '<script>unsafe</script>') || str_contains($html,'private-unit-secret') || !str_contains($html,'value="1000"')
        || !preg_match('/id="mail-priority"[^>]*value="0"/', $html)) { throw new RuntimeException('Quota template escaping/value regression'); }
    echo "PASS: empty/populated/rotation Twig templates render with strict variables, quota values, zero priority and escaped names; no kernel/database/network\n";
} finally {
    foreach (glob($root.'/project-multimail-private/*') as $file) { unlink($file); }
    rmdir($root.'/project-multimail-private'); rmdir($root.'/project'); rmdir($root);
}
