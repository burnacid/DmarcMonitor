<?php

namespace Tests\Unit;

use App\Services\Smtp\SmtpSession;
use PHPUnit\Framework\TestCase;

class SmtpSessionTest extends TestCase
{
    /** @var list<string> */
    private array $received = [];

    private function session(int $maxBytes = 1024, bool $accept = true): SmtpSession
    {
        return new SmtpSession($maxBytes, function (string $message) use ($accept): bool {
            $this->received[] = $message;

            return $accept;
        });
    }

    private function startMessage(SmtpSession $session): void
    {
        $session->receive('EHLO relay.example.com');
        $session->receive('MAIL FROM:<a@example.com>');
        $session->receive('RCPT TO:<dmarc@example.com>');
        $this->assertSame(['354 End data with <CR><LF>.<CR><LF>'], $session->receive('DATA'));
    }

    public function test_it_accepts_a_full_dialogue_and_hands_over_the_message(): void
    {
        $session = $this->session();

        $this->assertStringStartsWith('220 ', $session->greeting());
        $this->assertStringContainsString('250-SIZE 1024', implode("\n", $session->receive('ehlo relay.example.com')));
        $this->assertSame(['250 2.1.0 Sender ok'], $session->receive('MAIL FROM:<a@example.com>'));
        $this->assertSame(['250 2.1.5 Recipient ok'], $session->receive('RCPT TO:<dmarc@example.com>'));
        $this->assertSame(['354 End data with <CR><LF>.<CR><LF>'], $session->receive('DATA'));
        $this->assertSame([], $session->receive('Subject: Hi'));
        $this->assertSame([], $session->receive(''));
        $this->assertSame([], $session->receive('..leading dot'));
        $this->assertSame(['250 2.0.0 Message accepted'], $session->receive('.'));

        $this->assertSame(["Subject: Hi\r\n\r\n.leading dot\r\n"], $this->received);

        $this->assertStringStartsWith('221 ', $session->receive('QUIT')[0]);
        $this->assertTrue($session->isClosed());
    }

    public function test_it_asks_the_sender_to_retry_when_the_message_cannot_be_stored(): void
    {
        $session = $this->session(accept: false);
        $this->startMessage($session);

        $this->assertStringStartsWith('451 ', $session->receive('.')[0]);
    }

    public function test_an_oversized_message_is_rejected_and_the_session_stays_usable(): void
    {
        $session = $this->session(maxBytes: 20);
        $this->startMessage($session);

        $session->receive(str_repeat('x', 50));
        $session->receive(str_repeat('y', 50));

        $this->assertStringStartsWith('552 ', $session->receive('.')[0]);
        $this->assertSame([], $this->received);
        $this->assertSame(['250 2.1.0 Sender ok'], $session->receive('MAIL FROM:<a@example.com>'));
    }

    public function test_commands_out_of_order_are_rejected(): void
    {
        $session = $this->session();

        $this->assertStringStartsWith('503 ', $session->receive('MAIL FROM:<a@example.com>')[0]);

        $session->receive('HELO relay');

        $this->assertStringStartsWith('503 ', $session->receive('RCPT TO:<dmarc@example.com>')[0]);
        $this->assertStringStartsWith('503 ', $session->receive('DATA')[0]);
        $this->assertStringStartsWith('502 ', $session->receive('STARTTLS')[0]);
        $this->assertStringStartsWith('500 ', $session->receive('BOGUS')[0]);
    }

    public function test_starttls_is_advertised_and_requests_a_handshake_when_tls_is_available(): void
    {
        $session = new SmtpSession(1024, fn (): bool => true, tlsAvailable: true);

        $this->assertContains('250-STARTTLS', $session->receive('EHLO relay'));
        $this->assertFalse($session->takeTlsRequest());
        $this->assertSame(['220 2.0.0 Ready to start TLS'], $session->receive('STARTTLS'));
        $this->assertTrue($session->takeTlsRequest());
        $this->assertFalse($session->takeTlsRequest());

        $session->tlsEstablished();

        $this->assertStringStartsWith('503 ', $session->receive('MAIL FROM:<a@example.com>')[0]);
        $this->assertNotContains('250-STARTTLS', $session->receive('EHLO relay'));
        $this->assertStringStartsWith('503 ', $session->receive('STARTTLS')[0]);
        $this->assertSame(['250 2.1.0 Sender ok'], $session->receive('MAIL FROM:<a@example.com>'));
    }

    public function test_starttls_is_not_offered_without_tls(): void
    {
        $session = $this->session();

        $this->assertNotContains('250-STARTTLS', $session->receive('EHLO relay'));
        $this->assertStringStartsWith('502 ', $session->receive('STARTTLS')[0]);
        $this->assertFalse($session->takeTlsRequest());
    }

    public function test_mail_is_refused_before_tls_when_tls_is_required(): void
    {
        $session = new SmtpSession(1024, fn (): bool => true, tlsAvailable: true, tlsRequired: true);
        $session->receive('EHLO relay');

        $this->assertStringStartsWith('530 ', $session->receive('MAIL FROM:<a@example.com>')[0]);

        $session->receive('STARTTLS');
        $session->tlsEstablished();
        $session->receive('EHLO relay');

        $this->assertSame(['250 2.1.0 Sender ok'], $session->receive('MAIL FROM:<a@example.com>'));
    }

    public function test_rset_discards_the_envelope(): void
    {
        $session = $this->session();
        $session->receive('EHLO relay');
        $session->receive('MAIL FROM:<a@example.com>');
        $session->receive('RSET');

        $this->assertStringStartsWith('503 ', $session->receive('RCPT TO:<dmarc@example.com>')[0]);
    }
}
