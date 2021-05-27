<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class APIAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authorization = $request->header('Authorization');
        $authorization_key = md5(config('app.api_secret_salt') . config('app.api_secret_key'));
        if ($authorization != $authorization_key) {
            abort(Response::HTTP_UNAUTHORIZED, 'unknown api key.');
        }

        return $next($request);
    }
}
