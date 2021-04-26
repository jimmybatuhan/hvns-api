<?php

namespace App\Shopify\Mixins;

use Closure;
use Illuminate\Support\Arr;

class MetafieldMixin
{
    public function metafield(): Closure
    {
        /**
         * @return array|string
         */
        return function (string $namespace, string $key, ?string $meta_key = null)
        {
            $metafields = $this->collect()['metafields'];
            $metafield = Arr::first($metafields, fn ($metafield) =>
                $metafield['namespace'] === $namespace && $metafield['key'] === $key
            );

            return $meta_key && $metafield ? $metafield[$meta_key] : $metafield;
        };
    }
}