<?php

namespace STS\HeloEmail\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use STS\HeloEmail\HeloEmailServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HeloEmailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('helo.key', 'api-key');
        $app['config']->set('helo.channel_id', 'channel-id');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }
}
