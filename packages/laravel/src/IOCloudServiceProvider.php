<?php

namespace IOCloud\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class IOCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/iocloud.php', 'iocloud');

        $this->app->singleton(IOCloudClient::class, function ($app): IOCloudClient {
            return new IOCloudClient(
                http: $app->make(HttpFactory::class),
                clientId: (string) config('iocloud.client_id'),
                clientSecret: (string) config('iocloud.client_secret'),
                baseUrl: (string) config('iocloud.base_url'),
                timeout: (float) config('iocloud.timeout', 30),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/iocloud.php' => config_path('iocloud.php'),
        ], 'iocloud-config');
    }
}
