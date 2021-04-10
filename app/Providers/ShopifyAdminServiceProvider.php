<?php

namespace App\Providers;

use App\Shopify\ShopifyAdmin;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class ShopifyAdminServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        App::bind('shopify', function () {
            return new ShopifyAdmin;
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
