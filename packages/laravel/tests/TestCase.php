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

        // Federation stays off by default: the package must be fully usable for
        // tenant provisioning without a signing key. Cases that need it opt in.
        $app['config']->set('iocloud.federation.issuer', null);
        $app['config']->set('iocloud.federation.private_key', null);
        $app['config']->set('iocloud.federation.private_key_path', null);
    }
}
