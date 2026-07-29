<?php

namespace App\Providers;

use App\Console\Commands\RegisterFederationCommand;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RegisterFederationCommand::class]);
        }
    }
}
