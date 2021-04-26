<?php

namespace App\Providers;

use App\ShopPromo\ShopPromo;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class ShopPromoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        App::bind('promo', function () {
            return new ShopPromo;
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
