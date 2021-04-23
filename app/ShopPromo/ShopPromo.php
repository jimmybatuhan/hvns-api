<?php

namespace App\ShopPromo;

use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Illuminate\Support\Collection;
use Sheets;

class ShopPromo
{
    public const REWARD_TYPE_PRECISE = 'precise';
    public const REWARD_TYPE_PERCENTAGE = 'percentage';
    public const REWARD_TYPE_KEY = 'REWARD TYPE';
    public const REWARD_AMOUNT_KEY = 'REWARD AMOUNT';
    public const PROMOTION_SKU_KEY = 'BARCODE';

    private $sheet_id;

    public function __construct()
    {
        $this->sheet_id = config('app.sheet_id');
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
        return $this->getPromotions()
            ->filter(fn (Collection $promo) => $promo[self::PROMOTION_SKU_KEY] === $sku)
            ->isEmpty();
    }

    public function getPromotion(string $sku): Collection
    {
        return $this->getPromotions()
            ->filter(fn (Collection $promo) => $promo[self::PROMOTION_SKU_KEY] === $sku);
    }

    /**
     * There will an issue here where the ZAP 'earn points' API will automatically compute the points which is 2%
     * where as we should be able to specify what points a transaction should earn.
     *
     * TODO: make this by batch for faster result
     */
    public function calculatePoints(string $sku, int $quantity, float $amount): float
    {
        $promotion = $this->getPromotion($sku)->first();
        $default_points = $amount * ZAPConstants::ZAP_REWARD_PECENTAGE;
        $points = $default_points;

        if (! $promotion->isEmpty()) {
            $reward_type = trim(strtolower($promotion[self::REWARD_TYPE_KEY]));
            $reward_amount = floatval($promotion[self::REWARD_AMOUNT_KEY]);

            if ($reward_amount > 0) {
                switch ($reward_type) {
                    case self::REWARD_TYPE_PRECISE:
                        $points = $reward_amount;
                        break;
                    case self::REWARD_TYPE_PERCENTAGE:
                        $points = $amount * ($reward_amount / 100);
                        break;
                    default:
                        // Log a warning, unknown reward type, resolve by using the default 2%
                }
            } else {
                // Log a warning, promo has a zero or an invalid value
            }
        }
        return floatval($points * $quantity);
    }
}