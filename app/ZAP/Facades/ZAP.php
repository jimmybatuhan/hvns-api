<?php

namespace App\ZAP\Facades;

use Illuminate\Support\Facades\Facade;

class ZAP extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'zap';
    }
}