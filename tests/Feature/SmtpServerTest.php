<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\ForensicReport;
use App\Services\Smtp\AllowedSources;
use App\Services\Smtp\IncomingMailHandler;
use App\Services\Smtp\SmtpServer;
use App\Services\Smtp\TlsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmtpServerTest extends TestCase
{
    use RefreshDatabase;

    private string $baseDir;

    private ?SmtpServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->baseDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smtp-test-'.Str::random(8);
        config(['dmarc.eml_import_path' => $this->baseDir]);
    }

    protected function tearDown(): void
    {
        $this->server?->close();
        $this->deleteDirectory($this->baseDir);

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * @param  list<string>  $allowedIps
     */
    private function startServer(array $allowedIps = ['127.0.0.1'], ?TlsSettings $tls = null): SmtpServer
    {
        $this->server = new SmtpServer('127.0.0.1', 0, new AllowedSources($allowedIps), 1024 * 1024, 30, 5, app(IncomingMailHandler::class)(...), $tls);
        $this->server->listen();

        return $this->server;
    }

    private function tlsSettings(bool $required = false): TlsSettings
    {
        $config = $this->baseDir.DIRECTORY_SEPARATOR.'openssl.cnf';
        $pemPath = $this->baseDir.DIRECTORY_SEPARATOR.'smtp.pem';
        mkdir($this->baseDir, 0755, true);
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");

        $options = ['config' => $config, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot generate a test certificate here.');
        }

        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, $options), $certificate);
        openssl_pkey_export($key, $privateKey, null, $options);
        file_put_contents($pemPath, $certificate.$privateKey);

        return new TlsSettings($pemPath, required: $required);
    }

    /**
     * @param  resource  $client
     */
    private function upgradeToTls(SmtpServer $server, $client): void
    {
        for ($i = 0; $i < 200; $i++) {
            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === true) {
                return;
            }

            $this->assertNotFalse($result, 'The TLS handshake failed.');
            $server->tick(0.02);
        }

        $this->fail('The TLS handshake did not complete.');
    }

    /**
     * @return resource
     */
    private function connect(SmtpServer $server)
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $client = stream_socket_client("tcp://127.0.0.1:{$server->port()}", $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
        stream_set_blocking($client, false);

        return $client;
    }

    /**
     * Send text (if any), pump the server, and return what the client received.
     *
     * @param  resource  $client
     */
    private function exchange(SmtpServer $server, $client, string $send = ''): string
    {
        if ($send !== '') {
            fwrite($client, $send);
        }

        $received = '';

        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.05);
            $received .= (string) fread($client, 65536);
        }

        return $received;
    }

    private function deliver(SmtpServer $server, string $fixturePath, bool $startTls = false): string
    {
        $client = $this->connect($server);
        $this->assertStringStartsWith('220 ', $this->exchange($server, $client));
        $this->exchange($server, $client, "EHLO relay.example.com\r\n");

        if ($startTls) {
            $this->assertStringStartsWith('220 ', $this->exchange($server, $client, "STARTTLS\r\n"));
            $this->upgradeToTls($server, $client);
            $this->exchange($server, $client, "EHLO relay.example.com\r\n");
        }

        $this->exchange($server, $client, "MAIL FROM:<a@example.com>\r\n");
        $this->exchange($server, $client, "RCPT TO:<dmarc@example.com>\r\n");
        $this->exchange($server, $client, "DATA\r\n");

        $raw = str_replace(["\r\n", "\n"], "\r\n", file_get_contents(base_path($fixturePath)));
        $raw = preg_replace('/^\./m', '..', $raw);

        return $this->exchange($server, $client, $raw."\r\n.\r\n");
    }

    public function test_a_delivered_aggregate_report_is_imported_directly(): void
    {
        $response = $this->deliver($this->startServer(), 'tests/Fixtures/dmarc/eml/aggregate-report.eml');

        $this->assertStringStartsWith('250 ', $response);
        $this->assertDatabaseCount('aggregate_reports', 1);
        $this->assertEquals('google.com', AggregateReport::first()->org_name);
        $this->assertCount(1, glob($this->baseDir.'/inbox/processed/*.eml'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ingestion.completed']);
    }

    public function test_a_delivered_forensic_report_is_imported_directly(): void
    {
        $response = $this->deliver($this->startServer(), 'tests/Fixtures/dmarc/forensic/complete-auth-failure.eml');

        $this->assertStringStartsWith('250 ', $response);
        $this->assertEquals('example.com', ForensicReport::first()->header_from);
    }

    public function test_mail_without_a_report_is_accepted_but_moved_to_failed(): void
    {
        $response = $this->deliver($this->startServer(), 'tests/Fixtures/dmarc/eml/no-report.eml');

        $this->assertStringStartsWith('250 ', $response);
        $this->assertDatabaseCount('aggregate_reports', 0);
        $this->assertCount(1, glob($this->baseDir.'/inbox/failed/*.eml'));
    }

    public function test_a_report_can_be_delivered_over_starttls(): void
    {
        $server = $this->startServer(tls: $this->tlsSettings());

        $response = $this->deliver($server, 'tests/Fixtures/dmarc/eml/aggregate-report.eml', startTls: true);

        $this->assertStringStartsWith('250 ', $response);
        $this->assertDatabaseCount('aggregate_reports', 1);
    }

    public function test_plain_text_delivery_is_still_accepted_when_tls_is_optional(): void
    {
        $server = $this->startServer(tls: $this->tlsSettings());

        $this->assertStringStartsWith('250 ', $this->deliver($server, 'tests/Fixtures/dmarc/eml/aggregate-report.eml'));
        $this->assertDatabaseCount('aggregate_reports', 1);
    }

    public function test_mail_without_tls_is_refused_when_tls_is_required(): void
    {
        $server = $this->startServer(tls: $this->tlsSettings(required: true));
        $client = $this->connect($server);
        $this->exchange($server, $client);
        $this->exchange($server, $client, "EHLO relay.example.com\r\n");

        $this->assertStringStartsWith('530 ', $this->exchange($server, $client, "MAIL FROM:<a@example.com>\r\n"));
    }

    public function test_connections_from_addresses_outside_the_allow_list_are_refused(): void
    {
        $server = $this->startServer(['10.0.0.0/8']);
        $client = $this->connect($server);

        $this->assertStringStartsWith('554 ', $this->exchange($server, $client));
    }
}
