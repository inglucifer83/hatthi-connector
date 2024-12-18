<?php

namespace Hatthi\Connector\Providers;

use Hatthi\Connector\Console\Commands\Connect;
use Hatthi\Connector\Console\Commands\Listen;
use Illuminate\Support\ServiceProvider;

class HatthiServiceProvider extends ServiceProvider {

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Connect::class,
                Listen::class,
            ]);
        }
    }
}
