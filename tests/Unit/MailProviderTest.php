<?php

namespace Tests\Unit;

use App\Support\MailProvider;
use PHPUnit\Framework\TestCase;

class MailProviderTest extends TestCase
{
    public function test_the_most_preferred_known_mx_decides_the_provider(): void
    {
        $this->assertSame(MailProvider::GoogleWorkspace, MailProvider::detect([
            'example-com.mail.protection.outlook.com.' => 20,
            'ASPMX.L.GOOGLE.COM.' => 1,
        ]));
    }

    public function test_an_mx_that_only_resembles_a_provider_host_is_not_detected(): void
    {
        $this->assertNull(MailProvider::detect([
            'mail.protection.outlook.com.attacker.test' => 0,
            'notgooglemail.com' => 5,
        ]));
    }
}
