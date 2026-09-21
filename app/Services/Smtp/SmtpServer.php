<?php

namespace App\Services\Smtp;

use Closure;
use RuntimeException;

class SmtpServer
{
    private const int MAX_LINE_BUFFER = 65536;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{stream: resource, ip: string, session: SmtpSession, buffer: string, lastActivity: int, handshaking: bool}> */
    private array $clients = [];

    private bool $stopping = false;

    /**
     * @param  Closure(string): bool  $onMessage
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly AllowedSources $allowedSources,
        private readonly int $maxMessageBytes,
        private readonly int $idleTimeout,
        private readonly int $maxConnections,
        private readonly Closure $onMessage,
        private readonly ?TlsSettings $tls = null,
    ) {}

    public function listen(): void
    {
        $sslOptions = [];

        if ($this->tls !== null) {
            if (! is_file($this->tls->certificatePath) || ($this->tls->privateKeyPath !== null && ! is_file($this->tls->privateKeyPath))) {
                throw new RuntimeException('The configured SMTP TLS certificate or private key file does not exist.');
            }

            $sslOptions = ['ssl' => array_filter([
                'local_cert' => $this->tls->certificatePath,
                'local_pk' => $this->tls->privateKeyPath,
                'passphrase' => $this->tls->passphrase,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ], fn ($value) => $value !== null)];
        }

        $server = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create($sslOptions),
        );

        if ($server === false) {
            throw new RuntimeException("Unable to listen on {$this->host}:{$this->port}: {$errorMessage} ({$errorCode})");
        }

        stream_set_blocking($server, false);
        $this->server = $server;
    }

    public function port(): int
    {
        $name = (string) stream_socket_get_name($this->server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(): void
    {
        while (! $this->stopping) {
            $this->tick(1.0);
        }

        $this->close();
    }

    public function close(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->drop($id);
        }

        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
    }

    public function tick(float $timeout = 1.0): void
    {
        $read = [$this->server];

        foreach ($this->clients as $client) {
            $read[] = $client['stream'];
        }

        $write = $except = null;
        $seconds = (int) $timeout;

        if (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1_000_000)) > 0) {
            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->accept();
                } else {
                    $this->readFrom((int) $stream);
                }
            }
        }

        $this->dropIdleClients();
    }

    private function accept(): void
    {
        $stream = @stream_socket_accept($this->server, 0, $peer);

        if ($stream === false) {
            return;
        }

        $ip = substr((string) $peer, 0, (int) strrpos((string) $peer, ':'));
        $ip = trim($ip, '[]');

        if (! $this->allowedSources->allows($ip)) {
            fwrite($stream, "554 5.7.1 Access denied\r\n");
            fclose($stream);

            return;
        }

        if (count($this->clients) >= $this->maxConnections) {
            fwrite($stream, "421 4.3.2 Too many connections, try again later\r\n");
            fclose($stream);

            return;
        }

        stream_set_blocking($stream, false);

        $session = new SmtpSession(
            $this->maxMessageBytes,
            $this->onMessage,
            tlsAvailable: $this->tls !== null,
            tlsRequired: $this->tls?->required ?? false,
        );
        $this->clients[(int) $stream] = [
            'stream' => $stream,
            'ip' => $ip,
            'session' => $session,
            'buffer' => '',
            'lastActivity' => time(),
            'handshaking' => false,
        ];

        fwrite($stream, $session->greeting()."\r\n");
    }

    private function readFrom(int $id): void
    {
        $client = &$this->clients[$id];

        if ($client['handshaking']) {
            $this->advanceHandshake($id);

            return;
        }

        $chunk = fread($client['stream'], 8192);

        if ($chunk === false || ($chunk === '' && feof($client['stream']))) {
            $this->drop($id);

            return;
        }

        // TLS decrypts whole records into an internal buffer that stream_select
        // cannot see, so keep reading until the stream has nothing more ready.
        while ($chunk !== '' && ($more = fread($client['stream'], 8192)) !== false && $more !== '') {
            $chunk .= $more;
        }

        $client['lastActivity'] = time();
        $client['buffer'] .= $chunk;

        while (($position = strpos($client['buffer'], "\n")) !== false) {
            $line = rtrim(substr($client['buffer'], 0, $position), "\r");
            $client['buffer'] = substr($client['buffer'], $position + 1);

            foreach ($client['session']->receive($line) as $response) {
                fwrite($client['stream'], $response."\r\n");
            }

            if ($client['session']->isClosed()) {
                $this->drop($id);

                return;
            }

            if ($client['session']->takeTlsRequest()) {
                // Bytes pipelined behind STARTTLS were sent in clear text; discard them.
                $client['buffer'] = '';
                $client['handshaking'] = true;

                return;
            }
        }

        if (strlen($client['buffer']) > self::MAX_LINE_BUFFER) {
            $this->drop($id);
        }
    }

    private function advanceHandshake(int $id): void
    {
        $client = &$this->clients[$id];
        $result = @stream_socket_enable_crypto($client['stream'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($result === false) {
            $this->drop($id);

            return;
        }

        if ($result === true) {
            $client['handshaking'] = false;
            $client['session']->tlsEstablished();
        }

        $client['lastActivity'] = time();
    }

    private function dropIdleClients(): void
    {
        foreach ($this->clients as $id => $client) {
            if (time() - $client['lastActivity'] > $this->idleTimeout) {
                @fwrite($client['stream'], "421 4.4.2 Timeout, closing connection\r\n");
                $this->drop($id);
            }
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
        }
    }
}
