<?php

namespace App\Shopify\Mixins;

use App\ZAP\Constants as ZAPConstants;
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

    public function ZAPMemberTotalPoints(): Closure
    {
        /**
         * @return array|string
         */
        return function (?string $meta_key = null)
        {
           return $this->metafield(ZAPConstants::MEMBER_NAMESPACE, ZAPConstants::MEMBER_POINTS_KEY, $meta_key);
        };
    }

    public function ZAPMemberId(): Closure
    {
        /**
         * @return array|string
         */
        return function (?string $meta_key = null)
        {
           return $this->metafield(ZAPConstants::MEMBER_NAMESPACE, ZAPConstants::MEMBER_ID_KEY, $meta_key);
        };
    }

    public function ZAPTransactions(): Closure
    {
        /**
         * @return array|string
         */
        return function (?string $meta_key = null)
        {
           return $this->metafield(ZAPConstants::TRANSACTION_NAMESPACE, ZAPConstants::TRANSACTION_LIST_KEY, $meta_key);
        };
    }

    public function lastZAPTransactionRefNo(): Closure
    {
        /**
         * @return array|string
         */
        return function (?string $meta_key = null)
        {
           return $this->metafield(ZAPConstants::TRANSACTION_NAMESPACE, ZAPConstants::LAST_TRANSACTION_KEY, $meta_key);
        };
    }

}