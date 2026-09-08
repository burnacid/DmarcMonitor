<?php

namespace App\Services\Imap;

use App\Models\ImapAccount;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

class ImapConnectionTester
{
    public function test(ImapAccount $account): string
    {
        $client = (new ClientManager())->make([
            'host' => $account->host,
            'port' => $account->port,
            'encryption' => $account->encryption === 'none' ? false : $account->encryption,
            'validate_cert' => true,
            'username' => $account->username,
            'password' => $account->password,
            'protocol' => $account->protocol,
        ]);

        try {
            $client->connect();
            $client->disconnect();

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
