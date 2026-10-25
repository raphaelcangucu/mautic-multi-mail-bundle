<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMultiMailBundle\Tests\Fixtures;

use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/**
 * SMTP server behind Symfony's stream seam, answering per command. $replies may hold a
 * Throwable to simulate a dropped connection at that stage (e.g. timeout after DATA).
 */
final class SimulatedSmtpServer extends AbstractStream
{
    public array $commands = [];
    public string $data = '';
    public array $replies = ['auth' => "235 2.7.0 Authentication successful\r\n", 'mail' => "250 2.1.0 Ok\r\n", 'rcpt' => "250 2.1.5 Ok\r\n", 'data_end' => "250 2.0.0 Ok: queued\r\n"];
    public ?\Closure $onDataEnd = null;
    private array $queue = ["220 smtp.test ESMTP\r\n"];
    private bool $inData = false;

    public function initialize(): void {}
    public function setHost(string $host): static { return $this; }
    public function setPort(int $port): static { return $this; }
    public function disableTls(): static { return $this; }
    public function setTimeout(float $timeout): static { return $this; }
    public function isTLS(): bool { return true; }
    public function flush(): void {}
    protected function getReadConnectionDescription(): string { return 'simulated smtp'; }

    public function write(string $bytes, bool $debug = true): void
    {
        if ($this->inData) {
            if ($bytes !== "\r\n.\r\n") { $this->data .= $bytes; return; }
            $this->inData = false;
            $this->commands[] = '.';
            if ($this->onDataEnd !== null) { ($this->onDataEnd)($this->data); }
            $this->reply('data_end');
            return;
        }
        $command = rtrim($bytes, "\r\n");
        $verb = strtoupper(strtok($command, ' :') ?: '');
        $this->commands[] = $verb === 'AUTH' ? 'AUTH' : $command;
        match ($verb) {
            'EHLO' => $this->queue = array_merge($this->queue, ["250-smtp.test\r\n", "250-SIZE 35882577\r\n", "250 AUTH PLAIN LOGIN\r\n"]),
            'HELO' => $this->queue[] = "250 smtp.test\r\n",
            'AUTH' => $this->reply('auth'),
            'MAIL' => $this->reply('mail'),
            'RCPT' => $this->reply('rcpt'),
            'DATA' => [$this->inData = true, $this->queue[] = "354 End data with <CR><LF>.<CR><LF>\r\n"],
            'QUIT' => $this->queue[] = "221 2.0.0 Bye\r\n",
            default => $this->queue[] = "250 2.0.0 Ok\r\n",
        };
    }

    public function readLine(): string
    {
        $line = array_shift($this->queue);
        if ($line instanceof \Throwable) { throw $line; }

        return $line ?? '';
    }

    private function reply(string $stage): void
    {
        $reply = $this->replies[$stage];
        if ($reply instanceof \Throwable) { $this->queue[] = $reply; return; }
        foreach (preg_split('/(?<=\r\n)/', $reply, -1, PREG_SPLIT_NO_EMPTY) as $line) { $this->queue[] = $line; }
    }
}
