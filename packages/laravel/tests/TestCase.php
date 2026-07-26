<?php

namespace IOCloud\Laravel\Tests;

use IOCloud\Laravel\IOCloudServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [IOCloudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('iocloud.client_id', 'client-id');
        $app['config']->set('iocloud.client_secret', 'client-secret');
        $app['config']->set('iocloud.base_url', 'https://api.example.com');
    }
}
