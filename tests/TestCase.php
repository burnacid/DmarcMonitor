<?php

namespace Tests;

use App\Support\OutboundUrl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep webhook URL checks off live DNS: hostnames resolve to a public
        // address unless a test binds its own resolver.
        $this->app->instance(OutboundUrl::class, new OutboundUrl(fn (string $host) => ['93.184.215.14']));
    }
}
