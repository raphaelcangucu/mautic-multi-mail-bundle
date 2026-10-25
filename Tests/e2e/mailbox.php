<?php

declare(strict_types=1);

/*
 * GreenMail helper for the live app e2e (bench only):
 *   php Tests/e2e/mailbox.php code <operator-email>          prints the newest Mautic Inbox access code
 *   php Tests/e2e/mailbox.php expect <mailbox> <text> [secs] prints the matching message's threading headers
 * Exits 1 when nothing arrives in time.
 */

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
require is_file($root.'/build/package-vendor/autoload.php') ? $root.'/build/package-vendor/autoload.php' : $root.'/build/dependencies/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MauticPlugin\\MauticMultiMailBundle\\';
    if (str_starts_with($class, $prefix) && is_file($file = $root.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) { require $file; }
});

use MauticPlugin\MauticMultiMailBundle\Conversation\InboundParser;
use MauticPlugin\MauticMultiMailBundle\Imap\ImapClient;

[$command, $mailbox] = [$argv[1] ?? '', $argv[2] ?? ''];
$deadline = microtime(true) + (int) ($argv[4] ?? 40);
$parser = new InboundParser();
do {
    $client = ImapClient::open('127.0.0.1', 993);
    $client->login($mailbox, 'secret');
    $client->examine('INBOX');
    $uids = $client->uidsAfter(0);
    $items = $uids === [] ? [] : $client->fetch($uids, 'body');
    $client->logout();
    foreach (array_reverse($items, true) as $item) {
        $email = $parser->parse($item['raw']);
        if ($command === 'code' && preg_match('/Código de acesso: (\d{6})/u', (string) $email->text, $match)) { echo $match[1], "\n"; exit(0); }
        if ($command === 'expect' && str_contains($email->text.' '.$email->html, $argv[3] ?? "\0")) {
            echo json_encode(['subject' => $email->subject, 'message_id' => $email->messageId, 'in_reply_to' => $email->inReplyTo ?? null,
                'references' => $email->references ?? [], 'from' => $email->from ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            exit(0);
        }
    }
    usleep(500000);
} while (microtime(true) < $deadline);
fwrite(STDERR, "nothing for $command in $mailbox\n");
exit(1);
