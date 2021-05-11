<?php

namespace App\Shopify\Mixins;

use App\Shopify\Constants as ShopifyConstants;
use App\ZAP\Constants as ZAPConstants;
use Closure;
use Illuminate\Support\Arr;

class MetafieldMixin
{
    public function metafield(): Closure
    {
        /**
         * @return string|array|null
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
        return function (?string $meta_key = null)
        {
           return $this->metafield(
               ZAPConstants::MEMBER_NAMESPACE,
               ZAPConstants::MEMBER_POINTS_KEY,
               $meta_key
            );
        };
    }

    public function ZAPMemberId(): Closure
    {
        return function (?string $meta_key = null)
        {
           return $this->metafield(
               ZAPConstants::MEMBER_NAMESPACE,
               ZAPConstants::MEMBER_ID_KEY,
               $meta_key
            );
        };
    }

    public function ZAPTransactions(): Closure
    {
        return function (?string $meta_key = null)
        {
           return $this->metafield(
               ZAPConstants::TRANSACTION_NAMESPACE,
               ZAPConstants::TRANSACTION_LIST_KEY,
               $meta_key
            );
        };
    }

    public function lastZAPTransactionRefNo(): Closure
    {
        return function (?string $meta_key = null)
        {
           return $this->metafield(
               ZAPConstants::TRANSACTION_NAMESPACE,
               ZAPConstants::LAST_TRANSACTION_KEY,
               $meta_key
            );
        };
    }

    /**
     * Get the metafield id of the ZAP transaction list of an order
     */
    public function ZAPTransactionListMetaId(): Closure
    {
        return function ()
        {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_LIST_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function transactionList(): Closure
    {
        /**
         * @return array|null
         */
        return function ()
        {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_LIST_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE,
            ), true);
        };
    }

    /**
     * Gets the metafield id of the last ZAP transaction of an order
     */
    public function lastZAPTransactionMetaId(): Closure
    {
        return function ()
        {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LAST_TRANSACTION_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function lastZAPTransaction(): Closure
    {
        return function (): Array
        {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LAST_TRANSACTION_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            ), true);
        };
    }

}