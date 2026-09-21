<?php

namespace App\Console\Commands;

use App\Services\Smtp\AllowedSources;
use App\Services\Smtp\IncomingMailHandler;
use App\Services\Smtp\SmtpServer;
use App\Services\Smtp\TlsSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('dmarc:smtp-serve {--host= : Address to listen on} {--port= : Port to listen on}')]
#[Description('Run an SMTP listener that imports received DMARC report mails directly')]
class SmtpServe extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IncomingMailHandler $handler): int
    {
        $host = (string) ($this->option('host') ?? config('dmarc.smtp.host'));
        $port = (int) ($this->option('port') ?? config('dmarc.smtp.port'));

        $allowedSources = new AllowedSources(config('dmarc.smtp.allowed_ips'));
        $allowedSources->refresh();

        if ($allowedSources->resolvedRangeCount() > 0) {
            $this->line("Resolved {$allowedSources->resolvedRangeCount()} allowed range(s) from SPF records.");
        }

        $server = new SmtpServer(
            host: $host,
            port: $port,
            allowedSources: $allowedSources,
            maxMessageBytes: (int) config('dmarc.smtp.max_message_bytes'),
            idleTimeout: (int) config('dmarc.smtp.idle_timeout'),
            maxConnections: (int) config('dmarc.smtp.max_connections'),
            onMessage: $handler(...),
            tls: $this->tlsSettings(),
        );

        try {
            $server->listen();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $server->stop());
            pcntl_signal(SIGTERM, fn () => $server->stop());
        }

        $tlsMode = match (true) {
            config('dmarc.smtp.tls.certificate') === null => 'no TLS',
            (bool) config('dmarc.smtp.tls.required') => 'STARTTLS required',
            default => 'STARTTLS optional',
        };

        $this->info("SMTP listener running on {$host}:{$server->port()} ({$tlsMode}). Press Ctrl+C to stop.");

        $server->run();

        return self::SUCCESS;
    }

    private function tlsSettings(): ?TlsSettings
    {
        $certificate = config('dmarc.smtp.tls.certificate');

        if ($certificate === null || $certificate === '') {
            return null;
        }

        return new TlsSettings(
            certificatePath: (string) $certificate,
            privateKeyPath: config('dmarc.smtp.tls.key') ?: null,
            passphrase: config('dmarc.smtp.tls.passphrase') ?: null,
            required: (bool) config('dmarc.smtp.tls.required'),
        );
    }
}
