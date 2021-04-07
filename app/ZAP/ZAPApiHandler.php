<?php

namespace App\ZAP\Traits;

use Illuminate\Support\Collection;
use Throwable;

class ZAPApiHandler
{
    public const PURPOSE_USE_POINTS = 'USE_POINTS';
    public const PURPOSE_UPDATE_MEMBERSHIP = 'API_UPDATE_MEMBERSHIP';
    public const PURPOSE_GET_BALANCE = 'GET_BALANCE';

    // TODO must typehint the paramters
    private static function handleHttpError($response, Throwable $event): void
    {
        // TODO notify (slack or email) someone in charge and log the event
    }

    private static function handleResponseError(Collection $response): void
    {

    }
}