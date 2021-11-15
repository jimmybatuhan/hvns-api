<?php

namespace App\Shopify;

class Constants
{
    public const MINIMUM_SUBTOTAL_TO_EARN = 500;
    public const MAXIMUM_POINTS_TO_USE = 500;

    public const METAFIELD_INDEX_ID = 'id';
    public const METAFIELD_INDEX_VALUE = 'value';
    public const METAFIELD_INDEX_NAMESPACE = 'namespace';

    public const CUSTOMER_RESOURCE = 'customers';
    public const ORDER_RESOURCE = 'orders';

    public const FULFILLMENT_CANCELLED = 'cancelled';
    public const FULFILLMENT_FULFILLED = 'fulfilled';

    public const METAFIELD_VALUE_TYPE_STRING = 'string';
    public const METAFIELD_VALUE_TYPE_JSON_STRING = 'json_string';

    public const ELIGIBLE_500_TAG = "eligible-500";
    // public const ELIGIBLE_500_POINTS_NEEDED = 500;

    public const LESS_500 = "LESS_500";

    public const LESS_500_DISCOUNT_CODE = "CLAIM_500";

    public const USE_POINTS_PREFIX = "USP";
    public const USE_500_POINTS_PER_ITEM = "CLM";

    // public const MAX_CLAIMABLE = config('shopify-app.claim_promo_item_limit');

}
