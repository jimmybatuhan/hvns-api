<?php

namespace App\Providers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (! App::environment('local')) {
            URL::forceScheme('https');
        }

        throw_if(empty(config('app.shopify_api_version')), 'RuntimeException', 'Shopify API Version is not set on the env file.');
        throw_if(empty(config('app.shopify_store_url')), 'RuntimeException', 'Shopify Store URL is not set on the env file.');
        throw_if(empty(config('app.shopify_access_token')), 'RuntimeException', 'Shopify Store URL is not set on the env file.');
        throw_if(empty(config('app.shopify_app_secret')), 'RuntimeException', 'Shopify App Secret is not set on the env file.');

        throw_if(empty(config('app.zap_api_version')), 'RuntimeException', 'ZAP API Version is not set on the env file');
        throw_if(empty(config('app.zap_access_token')), 'RuntimeException', 'ZAP Access Token is not set on the env file.');
        throw_if(empty(config('app.zap_api_endpoint')), 'RuntimeException', 'ZAP API Endpoint is not set on the env file.');
        throw_if(empty(config('app.zap_branch_id')), 'RuntimeException', 'ZAP Branch ID is not set on the env file.');
        throw_if(empty(config('app.zap_merchant_id')), 'RsuntimeException', 'ZAP Merchant ID is not set on the env file.');
        throw_if(empty(config('app.zap_location_id')), 'RuntimeException', 'ZAP Location ID is not set on the env file.');

    }

}
