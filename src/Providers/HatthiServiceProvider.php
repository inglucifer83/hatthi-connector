<?php

namespace Hatthi\Connector\Providers;

use Hatthi\Connector\Console\Commands\Connect;
use Illuminate\Support\ServiceProvider;

class HatthiServiceProvider extends ServiceProvider {

    public function register() {

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Connect::class,
            ]);
        }
    }
}
