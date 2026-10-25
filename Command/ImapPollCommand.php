<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Command;

use MauticPlugin\MauticMultiMailBundle\Conversation\ConversationBridgeInterface;
use MauticPlugin\MauticMultiMailBundle\Imap\MailboxPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:multimail:imap:poll', description: 'Reads conversational IMAP mailboxes into the Inbox. Run from cron every minute.')]
final class ImapPollCommand extends Command
{
    public function __construct(private readonly MailboxPoller $poller, private readonly ConversationBridgeInterface $bridge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Only this Multi Mail connection id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->bridge->available()) {
            $output->writeln('Conversational email needs the Meta bundle; nothing to read.');
            return Command::SUCCESS;
        }
        $only = $input->getOption('connection');
        if ($only !== null && (!is_string($only) || !preg_match('/^[a-f0-9]{32}$/D', $only))) {
            $output->writeln('<error>Invalid connection id.</error>');
            return Command::INVALID;
        }
        $results = $this->poller->poll($only);
        if ($results === []) { $output->writeln('No conversational connection.'); }
        foreach ($results as $id => $result) {
            $details = [];
            foreach ($result['outcomes'] ?? [] as $outcome => $count) { $details[] = $outcome.'='.$count; }
            $output->writeln(sprintf('%s %s%s%s', $id, $result['status'], $details === [] ? '' : ' '.implode(' ', $details),
                isset($result['error']) ? ' — '.$result['error'] : ''));
        }

        // Mailbox failures are state, not a crash: cron keeps running and the screen shows them.
        return Command::SUCCESS;
    }
}
