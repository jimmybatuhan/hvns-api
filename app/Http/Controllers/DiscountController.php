<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class DiscountController extends Controller
{
    public function generateDiscountCode(Request $request): JsonResponse
    {
        $zap_response = ZAP::inquireBalance($request->mobile);
        $shopify_customer_id = $request->shopify_customer_id;
        $customer_balance = $zap_response->collect();
        $total_discount = 0;

        $exclusive_collection_id = env("CLAIM_500_COLLECTION_ID");
        $has_used_claim_500 = env("CLAIM_500") && $request->claim_500;
        $discount_code_prefix = $has_used_claim_500
            ? ShopifyConstants::USE_500_POINTS_PER_ITEM
            : ShopifyConstants::USE_POINTS_PREFIX;
        $discount_name = $discount_code_prefix . "-{$shopify_customer_id}";

        if ($zap_response->failed()) {
            return response()->json(['success' => false, 'message' => $customer_balance['error']]);
        }

        $available_customer_points = $customer_balance['data']['currencies'][0]['validPoints'];

        if ($has_used_claim_500) {

            $total_points_used = 0;
            $collection_response = ShopifyAdmin::getCollectionProducts($exclusive_collection_id);

            if ($collection_response->failed()) {
                return response()->json([
                    "success" => false,
                    "message" => "Failed to get collection products"
                ]);
            }

            $collection_products = $collection_response->collect();
            $collection_products = collect($collection_products["products"]);
            $collection_products = $collection_products->map(fn ($product) => $product["id"]);
            $cart_items = collect($request->items);
            $remaining_item_claims = config('shopify-app.claim_promo_item_limit');
            $cart_items->each(function (array $product) use (&$total_discount, &$total_points_used, &$remaining_item_claims, $collection_products) {
                /** check item if eligible for claim 500 */
                if (in_array($product["product_id"], $collection_products->toArray())) {
                    if ($remaining_item_claims > 0) {

                        $quantity = $product["quantity"];
                        if ($quantity > $remaining_item_claims) {
                            $quantity = $remaining_item_claims;
                        }
                        
                        $total_discount += ($product["final_price"] / 100) * $quantity;
                        $total_points_used += $quantity * config('shopify-app.claim_promo_points');
                        $remaining_item_claims = $remaining_item_claims - $quantity;
                    }
                }
            });

            if ((float) $total_points_used > (float) $available_customer_points) {
                $total_points_used = 0;
                $total_discount = 0;
            }

            $timestamp = Carbon::now()->timestamp;
            $discount_name .= "-{$timestamp}-{$total_points_used}";

            $this->resetActiveDiscountCodes($shopify_customer_id, $discount_name);
        } else {
            $points_to_use = $request->points_to_use ?? 0;
            if ($points_to_use > ShopifyConstants::MAXIMUM_POINTS_TO_USE) {
                $total_discount = ShopifyConstants::MAXIMUM_POINTS_TO_USE;
            } else {
                $total_discount = ($points_to_use > $available_customer_points || $points_to_use < 0)
                    ? $available_customer_points
                    : $points_to_use;
            }

            $total_points_used = $total_discount;
            $timestamp = Carbon::now()->timestamp;
            $discount_name .= "-{$timestamp}-{$total_points_used}";

            // check for existing discount name in customer metafield if has active discount codes, delete codes
            // store the new discount code
            $this->resetActiveDiscountCodes($shopify_customer_id, $discount_name);
        }

        $total_discount = strval($total_discount * -1);
        $price_rule_response = ShopifyAdmin::createPriceRule(
            $discount_name,
            $shopify_customer_id,
            $total_discount
        );

        $new_price_rule = $price_rule_response->collect();

        if ($price_rule_response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'failed to create a new price rule',
            ]);
        }

        $new_discount_code_response = ShopifyAdmin::createDiscountCode(
            $new_price_rule['price_rule']['id'],
            $discount_name
        );

        if ($new_discount_code_response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'failed to create a new discount',
            ]);
        }

        return response()->json([
            'success' => true,
            'discount_code' => $discount_name,
        ]);
    }

    public function getDiscountPoints(Request $request): JsonResponse
    {
        $response = [];
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|bail',
        ]);

        // TODO validate if the mobile no. exists in ZAP
        if (!$validator->fails()) {
            $zap_response = ZAP::inquireBalance($request->mobile);
            $customer_balance = $zap_response->collect();

            if (!$zap_response->failed()) {
                //TODO: Change this to for loop to get the correct currency if they have multiples
                $customer_current_points = $customer_balance['data']['currencies'][0]['validPoints'] ?? 0;
                $customer_pending_points = $customer_balance['data']['currencies'][0]['pendingPoints'] ?? 0;

                $response = [
                    'success' => true,
                    'available_points' => $customer_current_points,
                    'pending_points' => $customer_pending_points,
                ];
            } else {
                // TODO log the error
                $response = [
                    'success' => false,
                    'message' => $customer_balance['error'],
                ];
            }
        }

        return response()->json($response);
    }

    public function registerClaim500(Request $request)
    {
        $response = [
            'success' => 'false',
            'message' => ""
        ];

        $validator = Validator::make($request->all(), [
            'mobile' => 'required|bail',
            'shopify_customer_id' => 'required|bail',
        ]);

        // TODO validate if the mobile no. exists in ZAP
        if (!$validator->fails()) {
            // generate discount code name
            $shopify_customer_id = $request->shopify_customer_id;
            $shopify_customer_resp = ShopifyAdmin::getCustomerById($shopify_customer_id);
            $shopify_customer_data = $shopify_customer_resp->collect();
            $customer_tags = $shopify_customer_data['customer']['tags'];

            if (strpos($customer_tags, ShopifyConstants::ELIGIBLE_500_TAG) === false) {

                $zap_response = ZAP::inquireBalance($request->mobile);
                $customer_balance = $zap_response->collect();
                $available_customer_points = $customer_balance['data']['currencies'][0]['validPoints'];

                if ($available_customer_points >= ShopifyConstants::ELIGIBLE_500_POINTS_NEEDED) {

                    ZAP::deductPoints(ShopifyConstants::ELIGIBLE_500_POINTS_NEEDED, $request->mobile);
                    ShopifyAdmin::addTagsToCustomer($shopify_customer_id, ShopifyConstants::ELIGIBLE_500_TAG);
                    $response['success'] = true;
                } else {
                    $response['message'] = "Insufficient Balance";
                }
            } else {
                $response['message'] = "User is already Claim 500 Eligible";
            }
        }

        return response()->json($response);
    }

    private function resetActiveDiscountCodes(string $shopify_customer_id, string $discount_name): bool
    {
        try {
            $metafields = ShopifyAdmin::fetchMetafield($shopify_customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $active_discount_code_id = $metafields->ActiveDiscountCodeId();
            $active_discount_code = collect();
            $active_discount_code->push([
                "key" => "last_active_discount",
                "namespace" => ZAPConstants::MEMBER_NAMESPACE,
                "value" => $discount_name,
            ]);

            if (empty($active_discount_code_id)) {
                ShopifyAdmin::addMetafields(
                    ShopifyConstants::CUSTOMER_RESOURCE,
                    $shopify_customer_id,
                    $active_discount_code
                );
            } else {
                $current_active_discount = $metafields->ActiveDiscountCode();
                $discount = ShopifyAdmin::getDiscountCode($current_active_discount)->collect();
                $price_rule_id = $discount["discount_code"]["price_rule_id"];
                $discount_id = $discount["discount_code"]["id"];
                $usage_count = $discount["discount_code"]["usage_count"];

                if ($usage_count == 0) {
                    ShopifyAdmin::deleteDiscountCode($discount_id, $price_rule_id);
                }
                
                ShopifyAdmin::updateMetafieldById($active_discount_code_id["id"], $discount_name);
            }
        } catch (Exception $e) {
            report($e);
            return false;
        }

        return true;
    }
}
