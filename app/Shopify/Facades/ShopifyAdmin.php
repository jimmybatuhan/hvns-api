<?php

namespace App\Shopify\Facades;

use Illuminate\Support\Facades\Facade;

class ShopifyAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'shopify';
    }
}