<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Conversation;

use MauticPlugin\MauticMultiMailBundle\Application\ConnectionStore;
use MauticPlugin\MauticMultiMailBundle\Imap\{ImapConnectorInterface, ImapException};
use Symfony\Component\Mime\{Address, Email};

/**
 * "Testar ida e volta": the proof that a mailbox accepts +tags. Sends through the SMTP side
 * to `local+teste<random>@domain`, then reads the IMAP folder for up to 60 seconds.
 * Without +tag support the connection records `plus_addressing: false` and replies carry
 * no token; threading then relies on the headers and the sender.
 */
final class RoundTripTester
{
    public const WAIT_SECONDS = 60;
    public const INTERVAL_SECONDS = 3;

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly ConversationalSender $sender,
        private readonly ImapConnectorInterface $connector,
        private readonly InboundParser $parser,
    ) {}

    /** @return array{status: string, plus_addressing: ?bool, address: string, reference: string, delivered_to?: string, seconds?: int, reason?: string} */
    public function run(string $id, int $revision, int $waitSeconds = self::WAIT_SECONDS, ?\Closure $sleep = null): array
    {
        $connection = $this->store->transportChain($id, $revision)[0];
        if ($connection['provider'] !== 'smtp' || ($connection['settings']['imap_host'] ?? '') === '') {
            throw new \InvalidArgumentException('mautic.multimail.roundtrip.not_configured');
        }
        $account = ConnectionStore::accountAddress($connection);
        $at = (int) strrpos($account, '@');
        $reference = bin2hex(random_bytes(4));
        $address = substr($account, 0, $at).'+teste'.$reference.substr($account, $at);
        $messageId = '<'.bin2hex(random_bytes(16)).'.roundtrip@'.substr($account, $at + 1).'>';
        $email = (new Email())->from(new Address($connection['from_email'], $connection['from_name']))->to($address)
            ->subject('[Multi Mail] Teste de ida e volta · '.$reference)
            ->text("Teste automático do Multi Mail: confere se a caixa aceita endereço com +tag.\nReferência: ".$reference."\nPode apagar esta mensagem.");
        $email->getHeaders()->addIdHeader('Message-ID', trim($messageId, '<>'));
        $base = ['address' => $address, 'reference' => $reference];
        $outcome = $this->sender->send($connection, $email);
        if ($outcome->status === SendOutcome::PERMANENT) {
            // The server refused the tagged recipient outright: the mailbox has no +tags.
            $this->store->recordPlusAddressing($id, false);
            return $base + ['status' => 'rejected', 'plus_addressing' => false, 'reason' => $outcome->reason];
        }
        if ($outcome->status !== SendOutcome::ACCEPTED) {
            return $base + ['status' => 'send_failed', 'plus_addressing' => null, 'reason' => $outcome->reason];
        }
        $sleep ??= static function (int $seconds): void { sleep($seconds); };
        try {
            $client = $this->connector->connect($connection);
        } catch (ImapException $exception) {
            return $base + ['status' => 'imap_failed', 'plus_addressing' => null, 'reason' => $exception->getMessage()];
        }
        try {
            for ($elapsed = 0; ; $elapsed += self::INTERVAL_SECONDS) {
                // EXAMINE again on every round: some servers only show new mail to a fresh selection.
                $client->examine($connection['settings']['imap_folder']);
                $uids = $client->uidsByMessageId($messageId);
                if ($uids !== []) {
                    $headers = $client->fetch([max($uids)], 'header')[max($uids)]['raw'] ?? '';
                    $delivered = $address;
                    try {
                        $parsed = $this->parser->parse((string) $headers, true);
                        foreach ($parsed->recipientAddresses() as $candidate) { if (str_contains($candidate, '+teste'.$reference)) { $delivered = $candidate; break; } }
                    } catch (\Throwable) { /* Arrival is the proof; the header is only shown. */ }
                    $this->store->recordPlusAddressing($id, true);

                    return $base + ['status' => 'arrived', 'plus_addressing' => true, 'delivered_to' => $delivered, 'seconds' => $elapsed];
                }
                if ($elapsed >= $waitSeconds) { break; }
                $sleep(self::INTERVAL_SECONDS);
            }
        } catch (ImapException $exception) {
            return $base + ['status' => 'imap_failed', 'plus_addressing' => null, 'reason' => $exception->getMessage()];
        } finally {
            $client->logout();
        }
        $this->store->recordPlusAddressing($id, false);

        return $base + ['status' => 'not_arrived', 'plus_addressing' => false, 'seconds' => $waitSeconds];
    }
}
