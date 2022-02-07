<?php

namespace App\Shopify\Mixins;

use App\Shopify\Constants as ShopifyConstants;
use App\ZAP\Constants as ZAPConstants;
use Closure;
use Exception;
use Illuminate\Support\Arr;

class MetafieldMixin
{
    public function metafield(): Closure
    {
        /**
         * @return string|array|null
         */
        return function (string $namespace, string $key, ?string $meta_key = null) {
            try {
                $metafields = $this->collect()['metafields'];
                $metafield = Arr::first(
                    $metafields,
                    fn ($metafield) =>
                    $metafield['namespace'] === $namespace && $metafield['key'] === $key
                );
                return $meta_key && $metafield ? $metafield[$meta_key] : $metafield;
            } catch (Exception $e) {
                report($e);
                return "N/A";
            }
        };
    }

    public function ZAPMemberTotalPoints(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_POINTS_KEY,
                $meta_key
            );
        };
    }

    public function ZAPMemberId(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_ID_KEY,
                $meta_key
            );
        };
    }

    public function MemberBirthdayId(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_BIRTHDAY_KEY,
                $meta_key
            );
        };
    }

    public function ActiveDiscountCodeId(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                "last_active_discount",
                $meta_key
            );
        };
    }

    public function MemberSinceId(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_SINCE_KEY,
                $meta_key
            );
        };
    }

    public function MemberGenderId(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_GENDER_KEY,
                $meta_key
            );
        };
    }

    public function ZAPTransactions(): Closure
    {
        return function (?string $meta_key = null) {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_LIST_KEY,
                $meta_key
            );
        };
    }

    public function lastZAPTransactionRefNo(): Closure
    {
        return function (?string $meta_key = null) {
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
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_LIST_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function ActiveDiscountCode(): Closure
    {
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                "last_active_discount",
                ShopifyConstants::METAFIELD_INDEX_VALUE,
            ));
        };
    }

    public function transactionList(): Closure
    {
        /**
         * @return array|null
         */
        return function () {
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
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LAST_TRANSACTION_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function lastZAPTransaction(): Closure
    {
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LAST_TRANSACTION_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            ), true);
        };
    }

    public function getPointsToEarnMetafieldId(): Closure
    {
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_TO_EARN_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function getPointsToEarnMetafield(): Closure
    {
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_TO_EARN_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            ), true);
        };
    }

    public function getPointsToReturnMetafieldId(): Closure
    {
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_TO_RETURN_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function getPointsToReturnMetafield(): Closure
    {
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_TO_RETURN_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            ), true);
        };
    }

    /**
     * Get the metafield id of the line item promotions
     */
    public function getLineItemPointsMetafieldId(): Closure
    {
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LINE_ITEM_POINTS,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function getLineItemPointsMetafield(): Closure
    {
        /**
         * @return array|null
         */
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::LINE_ITEM_POINTS,
                ShopifyConstants::METAFIELD_INDEX_VALUE,
            ), true);
        };
    }

    /**
     * Get the metafield id of the points earned
     */
    public function getPointsEarnedMetafieldId(): Closure
    {
        return function () {
            return $this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_EARNED,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
        };
    }

    public function getPointsEarnedMetafield(): Closure
    {
        /**
         * @return array|null
         */
        return function () {
            return @json_decode($this->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::POINTS_EARNED,
                ShopifyConstants::METAFIELD_INDEX_VALUE,
            ), true);
        };
    }
}
