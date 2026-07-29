<?php

namespace IOCloud\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use IOCloud\Laravel\Console\KeysCommand;
use IOCloud\Laravel\Federation\FederationConfig;
use IOCloud\Laravel\Federation\FederationSigningKey;
use IOCloud\Laravel\Federation\SubjectTokenIssuer;
use IOCloud\Laravel\Http\Controllers\JwksController;

final class IOCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/iocloud.php', 'iocloud');

        $this->registerFederation();

        $this->app->singleton(IOCloudClient::class, function ($app): IOCloudClient {
            $federation = $app->make(FederationConfig::class);

            return new IOCloudClient(
                http: $app->make(HttpFactory::class),
                clientId: (string) config('iocloud.client_id'),
                clientSecret: (string) config('iocloud.client_secret'),
                baseUrl: (string) config('iocloud.base_url'),
                timeout: (float) config('iocloud.timeout', 30),
                // Wiring the issuer would need a signing key, so applications that
                // only provision tenants never have to configure federation.
                tokenIssuer: $federation->isConfigured()
                    ? $app->make(SubjectTokenIssuer::class)
                    : null,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/iocloud.php' => config_path('iocloud.php'),
        ], 'iocloud-config');

        if ($this->app->runningInConsole()) {
            $this->commands([KeysCommand::class]);
        }

        $this->registerJwksRoute();
    }

    private function registerFederation(): void
    {
        // Deliberately not a singleton: re-reading configuration on every
        // resolution keeps the object from going stale when the host application
        // changes `iocloud.federation` at runtime, which is how Laravel apps set
        // this up in tests. Building it is a handful of string reads.
        $this->app->bind(FederationConfig::class, static function (): FederationConfig {
            $federation = config('iocloud.federation');

            return FederationConfig::fromArray(is_array($federation) ? $federation : []);
        });

        $this->app->singleton(FederationSigningKey::class, static function ($app): FederationSigningKey {
            $federation = $app->make(FederationConfig::class);

            return FederationSigningKey::fromPrivateKeyPem(
                $federation->requirePrivateKeyPem(),
                $federation->privateKeyPassphrase,
            );
        });

        $this->app->singleton(SubjectTokenIssuer::class, static function ($app): SubjectTokenIssuer {
            $federation = $app->make(FederationConfig::class);

            return new SubjectTokenIssuer(
                signingKey: $app->make(FederationSigningKey::class),
                issuer: $federation->requireIssuer(),
                audience: $federation->audience,
                tokenTtlSeconds: $federation->tokenTtlSeconds,
                claimNames: $federation->claimNames,
            );
        });
    }

    /**
     * Optionally publish the JWKS at a configured path.
     *
     * Off by default: `IOCloud::jwks()` returns the document, so the route, its
     * URL, and its middleware stay in the host application's own routes file.
     * Setting `iocloud.federation.jwks_route` is a shortcut for applications that
     * would rather not write that line.
     */
    private function registerJwksRoute(): void
    {
        $jwksRoute = $this->app->make(FederationConfig::class)->jwksRoute;
        if ($jwksRoute === null) {
            return;
        }

        Route::get($jwksRoute, JwksController::class)->name('iocloud.federation.jwks');
    }
}
