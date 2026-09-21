<?php

namespace App\Services\Smtp;

use Closure;

/**
 * Protocol state machine for one inbound SMTP connection. Never relays: every
 * recipient is accepted and the finished message is handed to $onMessage,
 * which returns whether it was safely persisted.
 */
class SmtpSession
{
    private const string GREETED = 'greeted';

    private const string MAIL = 'mail';

    private const string RCPT = 'rcpt';

    private string $state = 'new';

    private bool $receivingData = false;

    private bool $tooLarge = false;

    private bool $closed = false;

    private string $message = '';

    private bool $secure = false;

    private bool $tlsRequested = false;

    /**
     * @param  Closure(string): bool  $onMessage
     */
    public function __construct(
        private readonly int $maxMessageBytes,
        private readonly Closure $onMessage,
        private readonly string $hostname = 'dmarc-monitor',
        private readonly bool $tlsAvailable = false,
        private readonly bool $tlsRequired = false,
    ) {}

    /**
     * True once, right after the STARTTLS reply was produced: the caller must
     * now run the TLS handshake on the connection.
     */
    public function takeTlsRequest(): bool
    {
        $requested = $this->tlsRequested;
        $this->tlsRequested = false;

        return $requested;
    }

    /**
     * Per RFC 3207 everything learned before the handshake is discarded, so
     * the client has to greet again.
     */
    public function tlsEstablished(): void
    {
        $this->secure = true;
        $this->state = 'new';
    }

    public function greeting(): string
    {
        return "220 {$this->hostname} ESMTP ready";
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @return list<string>
     */
    public function receive(string $line): array
    {
        return $this->receivingData ? $this->receiveDataLine($line) : $this->receiveCommand($line);
    }

    /**
     * @return list<string>
     */
    private function receiveDataLine(string $line): array
    {
        if ($line === '.') {
            $this->receivingData = false;
            $message = $this->message;
            $this->message = '';
            $this->state = self::GREETED;

            if ($this->tooLarge) {
                $this->tooLarge = false;

                return ['552 5.3.4 Message size exceeds fixed limit'];
            }

            return ($this->onMessage)($message)
                ? ['250 2.0.0 Message accepted']
                : ['451 4.3.0 Unable to store message, try again later'];
        }

        if ($this->tooLarge) {
            return [];
        }

        if (str_starts_with($line, '.')) {
            $line = substr($line, 1);
        }

        $this->message .= $line."\r\n";

        if (strlen($this->message) > $this->maxMessageBytes) {
            $this->tooLarge = true;
            $this->message = '';
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function receiveCommand(string $line): array
    {
        $verb = strtoupper(strtok(trim($line), ' ') ?: '');
        $argument = strtoupper(trim(substr(trim($line), strlen($verb))));

        switch ($verb) {
            case 'EHLO':
                $this->state = self::GREETED;

                return [
                    "250-{$this->hostname}",
                    ...($this->tlsAvailable && ! $this->secure ? ['250-STARTTLS'] : []),
                    "250-SIZE {$this->maxMessageBytes}",
                    '250 8BITMIME',
                ];
            case 'HELO':
                $this->state = self::GREETED;

                return ["250 {$this->hostname}"];
            case 'STARTTLS':
                if (! $this->tlsAvailable) {
                    return ['502 5.5.1 Command not implemented'];
                }

                if ($this->secure || $this->state === self::MAIL || $this->state === self::RCPT) {
                    return ['503 5.5.1 Bad sequence of commands'];
                }

                $this->tlsRequested = true;

                return ['220 2.0.0 Ready to start TLS'];
            case 'MAIL':
                if ($this->tlsRequired && ! $this->secure) {
                    return ['530 5.7.0 Must issue a STARTTLS command first'];
                }

                if ($this->state !== self::GREETED) {
                    return ['503 5.5.1 Bad sequence of commands'];
                }

                if (! str_starts_with($argument, 'FROM:')) {
                    return ['501 5.5.4 Syntax: MAIL FROM:<address>'];
                }

                if (preg_match('/\bSIZE=(\d+)/', $argument, $matches) && (int) $matches[1] > $this->maxMessageBytes) {
                    return ['552 5.3.4 Message size exceeds fixed limit'];
                }

                $this->state = self::MAIL;

                return ['250 2.1.0 Sender ok'];
            case 'RCPT':
                if ($this->state !== self::MAIL && $this->state !== self::RCPT) {
                    return ['503 5.5.1 Bad sequence of commands'];
                }

                if (! str_starts_with($argument, 'TO:')) {
                    return ['501 5.5.4 Syntax: RCPT TO:<address>'];
                }

                $this->state = self::RCPT;

                return ['250 2.1.5 Recipient ok'];
            case 'DATA':
                if ($this->state !== self::RCPT) {
                    return ['503 5.5.1 Bad sequence of commands'];
                }

                $this->receivingData = true;

                return ['354 End data with <CR><LF>.<CR><LF>'];
            case 'RSET':
                $this->state = $this->state === 'new' ? 'new' : self::GREETED;

                return ['250 2.0.0 Reset'];
            case 'NOOP':
                return ['250 2.0.0 Ok'];
            case 'QUIT':
                $this->closed = true;

                return ["221 2.0.0 {$this->hostname} closing connection"];
            case 'VRFY':
            case 'EXPN':
            case 'AUTH':
                return ['502 5.5.1 Command not implemented'];
            default:
                return ['500 5.5.2 Command not recognized'];
        }
    }
}
