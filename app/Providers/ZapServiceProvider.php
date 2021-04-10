<?php

namespace App\Providers;

use App\ZAP\Facades\ZapApiFacade;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class ZapServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        App::bind('zap', function () {
            return new ZapApiFacade;
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
