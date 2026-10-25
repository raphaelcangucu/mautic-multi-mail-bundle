<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// .eml corpus in Tests/Fixtures/mail only: no kernel, database or network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Conversation\{HtmlToMarkdown, InboundBody, InboundParser, QuoteCutter, ThreadToken, Triage};

function parsed(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

$token = (new ThreadToken('unit-secret'))->encode(31, 812);
$ourId = '<0123456789abcdef0123456789abcdef.31@macro.test>';
$fixture = static function (string $name) use ($token, $ourId): string {
    $raw = file_get_contents(__DIR__.'/Fixtures/mail/'.$name.'.eml');
    if ($raw === false) { throw new RuntimeException('Missing fixture '.$name); }
    // Real mail travels with CRLF; the corpus is stored with LF for readable diffs.
    return str_replace(["\r\n", "\n", '{{TOKEN}}', '{{OUR_ID}}', '{{OUR_ID_BARE}}'], ["\n", "\r\n", $token, $ourId, trim($ourId, '<>')], $raw);
};
$parser = new InboundParser();
$body = new InboundBody();
$read = static function (string $name) use ($parser, $body, $fixture): array {
    $email = $parser->parse($fixture($name));
    return [$email, $body->extract($email)];
};

[$email, $content] = $read('gmail-web-pt');
parsed($email->messageId === '<CAF3xGmailWebPt+abc123@mail.gmail.com>' && $email->inReplyTo === $ourId, 'Gmail ids');
parsed($email->references === ['<0123456789abcdef0123456789abcdef.31@macro.test>'], 'References deduplicated case-insensitively');
parsed($email->from === ['name' => 'João Lead', 'address' => 'joao@example.com'], 'RFC 2047 name, lowercased address');
parsed($email->subject === 'Re: Por que você ainda não depositou?', 'RFC 2047 subject');
parsed(ThreadToken::candidates($email->recipientAddresses(), 'contato@macro.test') === [$token], 'Token from To/Delivered-To');
parsed($content['text'] === 'Oi Ana, ainda estou decidindo. *Qual o depósito mínimo?*', 'Gmail web pt new text: '.json_encode($content['text']));
parsed(str_starts_with($content['quoted'], '> Em qui., 23 de out. de 2026 às 10:00') && str_contains($content['quoted'], '##- Responda acima desta linha -##'), 'Gmail quoted kept, attribution included');
parsed(str_contains($content['html'], 'Qual o depósito mínimo?') && !str_contains($content['html'], 'gmail_quote') && !str_contains($content['html'], 'Responda acima'), 'Gmail HTML cut by DOM');
parsed(Triage::kind($email) === Triage::MESSAGE && !Triage::isBulk($email) && !Triage::dmarcFailed($email), 'Gmail triage');

[$email, $content] = $read('gmail-ios-en');
parsed($content['text'] === "Sounds good, I'll deposit tomorrow." && str_starts_with($content['quoted'], '> On Thu, Oct 23, 2026'), 'Gmail iOS en');
parsed(ThreadToken::candidates($email->recipientAddresses(), 'contato@macro.test') === [$token] && in_array('contato@macro.test', $email->envelopeRecipients, true), 'X-Original-To and Delivered-To');

[$email, $content] = $read('outlook-web-pt');
parsed($email->subject === 'RE: Por que você ainda não depositou?', 'Mixed encoded-word subject');
parsed($content['text'] === "Oi Ana,\n\nVou depositar amanhã, obrigada pela atenção.\n\nAtt,\nMaria", 'Outlook web pt from ISO-8859-1: '.json_encode($content['text']));
parsed(str_starts_with($content['quoted'], '> ________________________________') && str_contains($content['quoted'], '> De: Ana · Macro Markets'), 'Outlook header block quoted');
parsed(str_contains($content['html'], 'obrigada pela atenção') && !str_contains($content['html'], 'divRplyFwdMsg') && !str_contains($content['html'], 'Oi Maria'), 'Outlook #divRplyFwdMsg cut');

[$email, $content] = $read('outlook-desktop-en');
parsed($content['text'] === "Hello Ana,\n\nI would like to know the minimum deposit first.\n\nRegards,\nPeter", 'Outlook desktop without sentinel');
parsed(str_starts_with($content['quoted'], '> -----Original Message-----'), 'Original Message block');
parsed(str_contains($content['html'], 'minimum deposit') && !str_contains($content['html'], 'Hi Peter') && !str_contains($content['html'], 'Sent:'), 'Outlook desktop border-top header cut');
parsed(ThreadToken::candidates($email->recipientAddresses(), 'contato@macro.test') === [], 'No token without +tag');

[$email, $content] = $read('apple-mail-pt');
parsed($email->messageId === '<7E1A2B3C-4D5E-6F70-8192-A3B4C5D6E7F8@icloud.example>', 'Message-Id spelling');
parsed($content['text'] === 'Combinado! Deposito na segunda.', 'Apple Mail pt: '.json_encode($content['text']));
parsed(str_starts_with($content['quoted'], '> Enviado do meu iPhone') && str_contains($content['quoted'], 'escreveu:'), 'Mobile signature and quote kept');
parsed(str_contains($content['html'], 'Combinado!') && !str_contains($content['html'], 'blockquote') && !str_contains($content['html'], 'escreveu'), 'Apple blockquote[type=cite] cut');

[$email, $content] = $read('thunderbird-en');
parsed($content['text'] === "Thanks, I'm waiting for my salary.", 'Thunderbird en: '.json_encode($content['text']));
parsed(str_starts_with($content['quoted'], '> --') && str_contains($content['quoted'], '> Hans Lead') && str_contains($content['quoted'], 'Ana · Macro Markets wrote:'), 'Signature moves to quoted');
parsed(!str_contains($content['html'], 'moz-cite-prefix') && !str_contains($content['html'], 'blockquote') && str_contains($content['html'], 'waiting for my salary'), 'Thunderbird cite prefix cut');
parsed($email->references === ['<earlier-from-lead@posteo.example>', $ourId], 'References order');

[$email, $content] = $read('interleaved-en');
parsed(str_contains($content['text'], "> What is your budget?\n\nAround 5k.") && str_contains($content['text'], 'Next week, after payday.'), 'Interleaved answers stay in the text');
parsed(!str_contains($content['text'], 'Rita') && str_contains($content['quoted'], 'Rita'), 'Interleaved signature still cut');

[$email, $content] = $read('html-only-pt');
parsed($email->text === '' && $email->from['name'] === 'João Lead', 'HTML-only, ISO-8859-1 name');
parsed($content['text'] === "Oi! Segue o **extrato** em anexo.\n\nMeu salário cai na sexta. Veja [meu saldo](https://bank.example/saldo).\n\n- Conta corrente\n- Poupança", 'HTML to markdown: '.json_encode($content['text']));
parsed($content['quoted'] !== '' && str_contains($content['quoted'], 'Oi João'), 'HTML-only quote as markdown');
parsed(!str_contains($content['text'], 'alert') && !str_contains($content['text'], 'color:red'), 'Script and style never reach the text');
$names = array_map(fn($attachment): string => $attachment->name.'|'.$attachment->mime.'|'.($attachment->contentId ?? '').'|'.($attachment->inline ? 'inline' : 'attachment'), $email->attachments);
parsed($names === ['logo.png|image/png|logo-001@example.com|inline', 'extrato.pdf|application/pdf||attachment', 'setup.exe|application/x-msdownload||attachment'], 'Attachments with cid: '.json_encode($names));
parsed(str_starts_with((string) $email->attachments[1]->content, '%PDF-1.4') && $email->attachments[1]->size === strlen((string) $email->attachments[1]->content), 'Decoded attachment bytes');

[$email] = $read('bounce-dsn');
parsed(Triage::kind($email) === Triage::BOUNCE, 'DSN is a bounce even with Auto-Submitted');
parsed($email->report === ['action' => 'failed', 'status' => '5.1.1', 'diagnostic' => 'smtp; 550 5.1.1 mailbox not found', 'recipient' => 'lost@example.com', 'original_message_id' => $ourId], 'DSN fields: '.json_encode($email->report));
parsed($email->attachments === [], 'Report parts are not attachments');

[$email] = $read('auto-reply-pt');
parsed(Triage::kind($email) === Triage::AUTO_REPLY, 'Auto-Submitted auto-reply');
[$email] = $read('outlook-ooo-en');
parsed(Triage::kind($email) === Triage::AUTO_REPLY, 'Vacation subject without headers');
[$email] = $read('list-en');
parsed(Triage::isBulk($email) && Triage::kind($email) === Triage::MESSAGE, 'List-Id and Precedence bulk');
[$email] = $read('echo');
parsed(Triage::isEcho($email, 'contato@macro.test') && !Triage::isEcho($email, 'other@macro.test'), 'Echo of our own message');
[$email] = $read('dmarc-fail');
parsed(Triage::dmarcFailed($email), 'Topmost Authentication-Results dmarc=fail, later forged header ignored');
[$email] = $read('gmail-ios-en');
parsed(!Triage::dmarcFailed($email), 'No Authentication-Results is not a failure');

foreach (["\x00\x01binary garbage", '', "no header line here\r\n\r\nbody"] as $garbage) {
    try { $parser->parse($garbage); throw new LogicException('Unreadable message accepted'); } catch (UnexpectedValueException) {}
}
$truncated = $parser->parse("From: Big <big@example.com>\r\nSubject: Huge\r\nMessage-ID: <big@example.com>\r\n\r\n", true);
parsed($truncated->truncated && $truncated->text === '' && $truncated->messageId === '<big@example.com>', 'Header-only parse of an oversized message');
$long = str_repeat('á', 40000);
parsed(strlen(InboundBody::cap($long)) <= 65536 && mb_check_encoding(InboundBody::cap($long), 'UTF-8'), '64 KiB cap on a UTF-8 boundary');
$cutter = new QuoteCutter();
parsed($cutter->cutText("> only quoted\n> text")['text'] === "> only quoted\n> text", 'Nothing above the quote keeps the whole message');
parsed($cutter->cutText("Plain answer\nwith two lines")['quoted'] === '', 'No quote, nothing quoted');
parsed((new HtmlToMarkdown())->convert('<p>a <a href="javascript:alert(1)">x</a> <b> b </b></p><table><tr><td>1</td><td>2</td></tr></table>') === "a x **b**\n\n1 | 2", 'Unsafe links lose the target; tables flatten');
echo "PASS: .eml corpus (Gmail web/iOS, Outlook web/desktop, Apple Mail, Thunderbird, pt/en, with/without sentinel, interleaved, HTML-only), charsets, RFC 2047, attachments/cid, DSN bounce, auto-replies, bulk, echo, DMARC and unreadable input; no kernel/database/network\n";
