<?php

namespace App\Shopify\Mixins;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class MetafieldMixin
{
    public function metafield(): Closure
    {
        /**
         * @return array|string
         */
        return function (string $namespace, string $key, ?string $meta_key)
        {
            $metafields = $this->collect()['metafields'];
            $metafield = Arr::first($metafields, fn ($metafield) =>
                $metafield['namespace'] === $namespace && $metafield['key'] === $key
            );

            return $meta_key ? $metafield[$meta_key] : $metafield;
        };
    }
}