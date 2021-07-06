<?php

namespace App\ShopPromo;

use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Illuminate\Support\Collection;
use Sheets;

class ShopPromo
{
    public const REWARD_TYPE_PRECISE = 'precise';
    public const REWARD_TYPE_MULTIPLIER = 'multiplier';
    public const REWARD_TYPE_KEY = 'REWARD TYPE';
    public const REWARD_AMOUNT_KEY = 'REWARD AMOUNT';
    public const PROMOTION_SKU_KEY = 'BARCODE';
    public const PROMOTION_TAB = 'Sheet1';

    private $promotions = [];
    private $sheet_id;

    public function __construct()
    {
        $this->sheet_id = config('app.sheet_id');

        /**
         * TODO organize the spreadsheet according to the product styles for faster lookup
         * will use the first sheet for now.
         * */
        $rows = Sheets::spreadsheet($this->sheet_id)->sheet(self::PROMOTION_TAB)->get();
        $headers = $rows->pull(0);

        $this->promotions = Sheets::collection($headers, $rows);
    }

    public function getPromotions(string $tab_name = ''): Collection
    {
        /**
         * TODO organize the spreadsheet according to the product styles for faster lookup
         * will use the first sheet for now.
         * */
        $rows = Sheets::spreadsheet($this->sheet_id)->sheet('Sheet1')->get();
        $headers = $rows->pull(0);

        return Sheets::collection($headers, $rows);
    }

    public function hasPromotion(string $sku): bool
    {
        return $this->promotions
            ->filter(fn (Collection $promo) => $promo[self::PROMOTION_SKU_KEY] === $sku)
            ->isEmpty();
    }

    public function getPromotion(string $sku): Collection
    {
        return $this->promotions
            ->filter(fn (Collection $promo) => $promo[self::PROMOTION_SKU_KEY] === $sku);
    }

    /**
     * There will an issue here where the ZAP 'earn points' API will automatically compute the points which is 2%
     * where as we should be able to specify what points a transaction should earn.
     *
     * TODO: make this by batch for faster result
     */
    public function calculatePoints(array $item): float
    {
        $calculated_points = 0;

        if ($item['fulfillable_quantity'] > 0) {

            $sku = $item['sku'];
            $quantity = $item['quantity'];
            $amount = floatval($item['price']);
            $promotion = $this->getPromotion($sku);
            $default_points = $amount * ZAPConstants::ZAP_REWARD_PECENTAGE;
            $points = $default_points;

            if (! $promotion->isEmpty()) {
                $promotion = $promotion->first();
                $reward_type = trim(strtolower($promotion[self::REWARD_TYPE_KEY]));
                $reward_amount = floatval($promotion[self::REWARD_AMOUNT_KEY]);

                if ($reward_amount > 0) {
                    switch ($reward_type) {
                        case self::REWARD_TYPE_PRECISE:
                            $points = $default_points + $reward_amount;
                            break;
                        case self::REWARD_TYPE_MULTIPLIER:
                            $points = $default_points * $reward_amount;
                            break;
                        default:
                            // Log a warning, unknown reward type, resolve by using the default 2%
                    }
                } else {
                    // Log a warning, promo has a zero or an invalid value
                }
            }

            $calculated_points = floatval($points * $quantity);
        }

        return $calculated_points;
    }
}