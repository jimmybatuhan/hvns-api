<?php

namespace App\ShopPromo;

use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Illuminate\Support\Collection;
use Sheets;

class ShopPromo
{
    public const REWARD_TYPE_DEFAULT = 'default';
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

     //This will be just for the order create function
    public function calculateInitialPointsToEarn(array $item): array
    {
        $calculated_points = 0;

        $sku = $item['sku'];
        $quantity = $item['quantity'];
        $amount = floatval($item['price']);
        $promotion = $this->getPromotion($sku);
        $reward_type = self::REWARD_TYPE_DEFAULT;
        $reward_amount = 0;
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

        $calculated_points = floatval($points);

        return [
            "id" => $item['id'],
            "reward_type" => $reward_type,
            "reward_amount" => $reward_amount,
            "points_to_earn" => $calculated_points,
        ];
    }

    public function calculatePointsToEarn(array $item, array &$line_item_points): array
    {
        $calculated_points = 0;
        $subtotal_amount = 0;

        $id = strval($item['id']);
        $amount = floatval($item['price']);
        $quantity = $item['quantity'];

        $reward_type = self::REWARD_TYPE_DEFAULT;
        $reward_amount = 0;

        if ( array_key_exists($id, $line_item_points) ) {
            $calculated_points = floatval($line_item_points[$id]["points_to_earn"] * $quantity);
            $reward_type = $line_item_points[$id]["reward_type"];
            $reward_amount = $line_item_points[$id]["reward_amount"];
        } else {
            $sku = $item['sku'];
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

        $subtotal_amount = floatval($amount * $quantity);
        $points_to_credit = $calculated_points;

        // if item is not fulfilled, dont include it on the calculation of earned points
        if($item['fulfillment_status'] != 'fulfilled'){
            $subtotal_amount = 0;
            $points_to_credit = 0;
        }

        return [
            "id" => $item['id'],
            "subtotal_amount" => $subtotal_amount,
            "reward_type" => $reward_type,
            "reward_amount" => $reward_amount,
            "points_to_credit" => $points_to_credit,
            "points_to_earn" => $calculated_points,
        ];
    }

    public function calculateRefundPoints(array $item, array &$line_item_points): array
    {
        $calculated_points = 0;
        $subtotal_amount = 0;

        $id = strval($item['id']);
        $amount = floatval($item['price']);
        $quantity = $item['quantity'];

        $reward_type = self::REWARD_TYPE_DEFAULT;
        $reward_amount = 0;

        if ( array_key_exists($id, $line_item_points) ) {
            $calculated_points = floatval($line_item_points[$id]["points_to_earn"] * $quantity);
            $reward_type = $line_item_points[$id]["reward_type"];
            $reward_amount = $line_item_points[$id]["reward_amount"];
        } else {
            $sku = $item['sku'];
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

        $subtotal_amount = floatval($amount * $quantity);
        $points_to_credit = $calculated_points;

        return [
            "id" => $item['id'],
            "subtotal_amount" => $subtotal_amount,
            "reward_type" => $reward_type,
            "reward_amount" => $reward_amount,
            "points_to_credit" => $points_to_credit,
            "points_to_earn" => $calculated_points,
        ];
    }
}