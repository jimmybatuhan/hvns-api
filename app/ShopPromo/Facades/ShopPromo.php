<?php

namespace App\ShopPromo\Facades;

use Illuminate\Support\Facades\Facade;

class ShopPromo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'promo';
    }
}