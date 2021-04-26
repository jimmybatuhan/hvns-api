<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogRoute
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): JSONResponse
    {
        $response = $next($request);

        Log::channel("http-request-slack")->info(collect([
            'URI' => $request->getUri(),
            'METHOD' => $request->getMethod(),
            'HEADERS' => $request->header(),
            'REQUEST_BODY' => $request->all(),
            'RESPONSE' => $response->getContent()
        ])->toJson());

        return $response;
    }
}
