<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $shopify_app_secret = config('app.shopify_app_secret');
        $hmac_header = $request->header('x-shopify-hmac-sha256');

        if (! $hmac_header) {
            abort(Response::HTTP_NOT_ACCEPTABLE);
        }

        $calculated_hmac = base64_encode(hash_hmac('sha256', $request->getContent(), $shopify_app_secret, true));

        if (hash_equals($hmac_header, $calculated_hmac)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
