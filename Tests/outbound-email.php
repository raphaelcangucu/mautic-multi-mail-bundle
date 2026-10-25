<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Pure MIME assembly: no kernel, database, filesystem or network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Conversation\{MarkdownRenderer, OutboundEmailBuilder, ThreadToken};

function mime(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

$builder = new OutboundEmailBuilder();
$token = (new ThreadToken('unit-secret'))->encode(31, 812);
$references = array_map(fn(int $n): string => '<ref'.$n.'@example.com>', range(1, 14));
$spec = ['from_email' => 'contato@macro.test', 'from_name' => OutboundEmailBuilder::senderName('Ana', 'Macro Markets'),
    'reply_to' => OutboundEmailBuilder::replyTo('contato@macro.test', $token), 'to_email' => 'Joao@Example.com', 'to_name' => 'João Lead',
    'subject' => "Re: Por que você ainda não depositou?\r\nBcc: injected@example.com", 'message_id' => '<0123456789abcdef0123456789abcdef.31@macro.test>',
    'in_reply_to' => '<CAF3x@mail.gmail.com>', 'references' => $references,
    'text' => "Oi **João**, tudo bem?\nQual o *melhor* horário?\n\n- [Formulário](https://macro.test/inbox/f/abc?x=1&y=2)\n- veja https://macro.test/a_b_c.\n\n<script>alert(1)</script>"];
$email = $builder->build($spec);
$raw = $email->toString();
$headers = $email->getHeaders();
mime($headers->get('Message-ID')->getBodyAsString() === '<0123456789abcdef0123456789abcdef.31@macro.test>', 'Explicit Message-ID kept');
mime($builder->build($spec)->getHeaders()->get('Message-ID')->getBodyAsString() === $headers->get('Message-ID')->getBodyAsString(), 'Same job, same Message-ID');
mime(str_contains($raw, 'From: =?utf-8?') || str_contains($raw, 'From: Ana'), 'From has the agent and connection names');
mime($email->getFrom()[0]->getName() === 'Ana · Macro Markets' && $email->getFrom()[0]->getAddress() === 'contato@macro.test', 'From is the account');
mime($email->getReplyTo()[0]->getAddress() === 'contato+'.$token.'@macro.test', 'Reply-To carries the token');
mime($email->getTo()[0]->getAddress() === 'joao@example.com', 'Lead address lowercased');
mime($headers->get('In-Reply-To')->getBodyAsString() === '<CAF3x@mail.gmail.com>', 'In-Reply-To');
$chain = $headers->get('References')->getIds();
mime(count($chain) === 10 && end($chain) === 'CAF3x@mail.gmail.com' && $chain[0] === 'ref6@example.com', 'References: the 10 most recent, ending with In-Reply-To');
mime(!str_contains($raw, "\nBcc:") && !$headers->has('Bcc') && !str_contains((string) $email->getSubject(), "\n"), 'No header injection through the subject');
mime(!$headers->has('List-Unsubscribe') && !$headers->has('List-Unsubscribe-Post'), 'Human reply has no List-Unsubscribe');
mime(str_contains($raw, 'multipart/alternative'), 'Both alternatives');
$text = $email->getTextBody(); $html = $email->getHtmlBody();
mime(str_starts_with($text, OutboundEmailBuilder::SENTINEL."\n\n"), 'Sentinel on top of the text part');
mime(str_contains($text, 'Formulário (https://macro.test/inbox/f/abc?x=1&y=2)') && str_contains($text, 'Oi João,'), 'Plain alternative is readable');
mime(str_contains($html, '##- Responda acima desta linha -##') && str_contains($html, 'color:#9aa0a6'), 'Grey sentinel on top of HTML');
mime(str_contains($html, '<strong>João</strong>') && str_contains($html, '<em>melhor</em>') && str_contains($html, '<br>'), 'Emphasis and line breaks');
mime(str_contains($html, '<a href="https://macro.test/inbox/f/abc?x=1&amp;y=2" style="color:#1a73e8">Formulário</a>'), 'Markdown link');
mime(str_contains($html, '<a href="https://macro.test/a_b_c" style="color:#1a73e8">https://macro.test/a_b_c</a>.'), 'Bare link without trailing dot and without emphasis inside');
mime(str_contains($html, '<ul ') && substr_count($html, '<li>') === 2, 'List');
mime(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Raw HTML escaped');
mime(!preg_match('/<img|track|pixel/i', $html), 'No tracking pixel');

$campaign = $builder->build(array_replace($spec, ['in_reply_to' => null, 'references' => [], 'reply_to' => OutboundEmailBuilder::replyTo('contato@macro.test', null),
    'from_name' => 'Macro Markets', 'unsubscribe_url' => 'https://macro.test/inbox/u/signed']));
mime($campaign->getHeaders()->get('List-Unsubscribe')->getBodyAsString() === '<https://macro.test/inbox/u/signed>'
    && $campaign->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString() === 'List-Unsubscribe=One-Click', 'Campaign one-click unsubscribe');
mime($campaign->getReplyTo()[0]->getAddress() === 'contato@macro.test' && !str_contains($campaign->toString(), '+r'), 'No token without plus addressing');
mime(!$campaign->getHeaders()->has('In-Reply-To') && !$campaign->getHeaders()->has('References'), 'First message has no thread headers');
foreach ([['unsubscribe_url' => 'http://insecure.test/u'], ['subject' => ' '], ['text' => ''], ['message_id' => 'not an id']] as $bad) {
    try { $builder->build(array_replace($spec, $bad)); throw new LogicException('Invalid spec accepted: '.json_encode($bad)); } catch (InvalidArgumentException) {}
}
mime(OutboundEmailBuilder::senderName('', 'Macro Markets') === 'Macro Markets' && OutboundEmailBuilder::senderName("Ana\r\n", 'Macro') === 'Ana · Macro', 'Sender names');
$markdown = new MarkdownRenderer();
mime($markdown->toHtml("> citado\n\n1. um\n2. dois") === '<blockquote style="margin:0 0 12px;padding-left:12px;border-left:3px solid #dadce0;color:#5f6368">citado</blockquote>'."\n".'<ol style="margin:0 0 12px;padding-left:24px"><li>um</li><li>dois</li></ol>', 'Quote and ordered list');
mime($markdown->toHtml('[x](javascript:alert(1))') === '<p style="margin:0 0 12px">[x](javascript:alert(1))</p>', 'Only http, https and mailto links');
mime($markdown->toHtml('snake_case_name e 2*3*4') === '<p style="margin:0 0 12px">snake_case_name e 2*3*4</p>', 'Intra-word markers stay text');
echo "PASS: outbound MIME headers, stable Message-ID, token Reply-To, thread headers (10), sentinel, both alternatives, markdown subset, escaping, campaign List-Unsubscribe only, no pixel; no kernel/database/network\n";
