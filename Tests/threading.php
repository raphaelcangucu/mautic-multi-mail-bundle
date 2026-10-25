<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Threading rules against an in-memory lookup: no kernel, database or network.
require __DIR__.'/bootstrap.php';

use MauticPlugin\MauticMultiMailBundle\Conversation\{InboundEmail, MessageIds, ThreadLookup, ThreadResolver, ThreadToken, Triage};

function thread(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }

final class MemoryLookup implements ThreadLookup
{
    public array $asked = [];
    public function __construct(private array $conversations, private array $messages, private array $recipients) {}
    public function hasConversation(int $conversationId): bool { return in_array($conversationId, $this->conversations, true); }
    public function conversationForExternalIds(array $externalIds): ?int
    {
        $this->asked = $externalIds;
        foreach ($externalIds as $id) { if (isset($this->messages[$id])) { return $this->messages[$id]; } }
        return null;
    }
    public function conversationForRecipient(string $recipient): ?int { return $this->recipients[$recipient] ?? null; }
}

function email(array $to = [], ?string $inReplyTo = null, array $references = [], string $from = 'joao@example.com', array $headers = [], array $envelope = []): InboundEmail
{
    return new InboundEmail('<incoming@example.com>', $inReplyTo, $references, ['name' => '', 'address' => $from],
        array_map(fn(string $address): array => ['name' => '', 'address' => $address], $to), [], $envelope, 'Re: x', '', 'text', '', [], $headers, null, false);
}

$tokens = new ThreadToken('unit-secret');
$resolver = new ThreadResolver($tokens);
$account = 'contato@macro.test';
$ours = '<0123456789abcdef0123456789abcdef.31@macro.test>';
$lookup = new MemoryLookup([812, 900], [MessageIds::externalId(31, $ours) => 900, MessageIds::externalId(31, '<lead-earlier@example.com>') => 901], ['joao@example.com' => 902]);
$valid = 'contato+'.$tokens->encode(31, 812).'@macro.test';

$result = $resolver->resolve(email([$valid], $ours, [$ours]), 31, $account, $lookup);
thread($result === ['rule' => 'token', 'conversation_id' => 812, 'invalid_token' => false], 'Token wins over headers and sender');
$result = $resolver->resolve(email(['contato@macro.test'], null, [], 'joao.trabalho@corp.example', [], [$valid]), 31, $account, $lookup);
thread($result['rule'] === 'token' && $result['conversation_id'] === 812, 'Reply from another address stays in the original conversation (Delivered-To)');
$foreign = 'contato+'.$tokens->encode(32, 812).'@macro.test';
$result = $resolver->resolve(email([$foreign], $ours), 31, $account, $lookup);
thread($result === ['rule' => 'headers', 'conversation_id' => 900, 'invalid_token' => true], 'Token of another account is invalid; headers decide');
$tampered = substr($valid, 0, 10).($valid[10] === 'a' ? 'b' : 'a').substr($valid, 11);
$result = $resolver->resolve(email([$tampered], null, ['<unknown@x>', '<lead-earlier@example.com>']), 31, $account, $lookup);
thread($result === ['rule' => 'headers', 'conversation_id' => 901, 'invalid_token' => true], 'Tampered token ignored; References match');
$gone = 'contato+'.$tokens->encode(31, 777).'@macro.test';
$result = $resolver->resolve(email([$gone]), 31, $account, $lookup);
thread($result === ['rule' => 'sender', 'conversation_id' => 902, 'invalid_token' => true], 'Token of a deleted conversation falls through to the sender');
$resolver->resolve(email([], '<c@x>', ['<a@x>', '<b@x>', '<c@x>']), 31, $account, $lookup);
thread($lookup->asked === [MessageIds::externalId(31, '<c@x>'), MessageIds::externalId(31, '<b@x>'), MessageIds::externalId(31, '<a@x>')], 'In-Reply-To first, then References newest first, no duplicates');
$many = array_map(fn(int $n): string => '<m'.$n.'@x>', range(1, 40));
$resolver->resolve(email([], null, $many), 31, $account, $lookup);
thread(count($lookup->asked) === ThreadResolver::MAX_REFERENCES && $lookup->asked[0] === MessageIds::externalId(31, '<m40@x>'), 'Reference lookups bounded');
$result = $resolver->resolve(email(['contato@macro.test'], null, [], 'JOAO@example.com'), 31, $account, $lookup);
thread($result === ['rule' => 'sender', 'conversation_id' => 902, 'invalid_token' => false], 'Sender match is case-insensitive');
$result = $resolver->resolve(email(['contato@macro.test'], '<unrelated@x>', [], 'new@example.com'), 31, $account, $lookup);
thread($result === ['rule' => 'new', 'conversation_id' => null, 'invalid_token' => false], 'Unknown sender opens a new conversation');
$result = $resolver->resolve(email(['contato+'.$tokens->encode(31, 812).'@other.test']), 31, $account, $lookup);
thread($result['rule'] === 'sender', 'Token on another domain is not ours');

$bulk = email([], null, [], 'news@example.com', ['list-id' => ['<news.example.com>']]);
thread(Triage::isBulk($bulk) && Triage::isBulk(email([], null, [], 'x@y.z', ['precedence' => ['Bulk']])) && !Triage::isBulk(email()), 'Bulk detection');
thread(!Triage::isAutoReply(email([], null, [], 'x@y.z', ['auto-submitted' => ['no']])) && Triage::isAutoReply(email([], null, [], 'x@y.z', ['x-autorespond' => ['1']])), 'Auto-Submitted: no is a person');
thread(Triage::isAutoReply(email([], null, [], 'x@y.z', ['precedence' => ['auto_reply']])), 'Precedence auto_reply');
echo "PASS: threading order token > headers > sender > new, other-address replies, foreign/tampered/stale tokens, reference order and bounds, case-insensitive sender, bulk and auto-reply triage; no kernel/database/network\n";
