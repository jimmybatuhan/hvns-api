<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use App\Shopify\Constants as ShopifyConstants;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Google\Service\ShoppingContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DiscountController extends Controller
{
    public function generateDiscountCode(Request $request): JsonResponse
    {
        $response = [];
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|bail',
            'shopify_customer_id' => 'required|bail',
            'points_to_use' => 'numeric|required|bail',
        ]);

        // TODO validate if the mobile no. exists in ZAP
        if (!$validator->fails()) {

            // generate discount code name
            $shopify_customer_id = $request->shopify_customer_id;
            $discount_code = $this->generateNameForDiscountCode($shopify_customer_id);
            $discount_name = $discount_code;
            // get customer balance (points)
            $zap_response = ZAP::inquireBalance($request->mobile);
            $customer_balance = $zap_response->collect();

            if (!$zap_response->failed()) {
                //TODO: Change this to for loop to get the correct currency if they have multiples
                $available_customer_points = $customer_balance['data']['currencies'][0]['validPoints'];

                //Limit the available customer points to 500
                if ($available_customer_points > ShopifyConstants::MAXIMUM_POINTS_TO_USE) {
                    $available_customer_points = ShopifyConstants::MAXIMUM_POINTS_TO_USE;
                }

                if ($request->points_to_use > $available_customer_points || $request->points_to_use < 0) {
                    $customer_current_points = strval($available_customer_points * -1);
                } else {
                    $customer_current_points = strval($request->points_to_use * -1);
                }

                $customer_detail = ShopifyAdmin::getCustomerById($shopify_customer_id)->collect();
                $customer_tags = explode(",", $customer_detail["tags"]);
                $total_discount_from_claim_500_promo = 0;
                $total_quantity_of_items_from_claim_500 = 0;

                /** if customer is registered for the 500 discounted items */
                if (in_array(ShopifyConstants::ELIGIBLE_500_TAG, $customer_tags)) {
                    /** if user purchase items that has less 500, calculate items that has tags for less 500 */
                    if ($request->has("items") && !empty($request->items)) {
                        $cart_items = collect($request->items);
                        $cart_items->each(function (array $product) use (&$total_quantity_of_items_from_claim_500, &$total_discount_from_claim_500_promo) {
                            $item_detail_response = ShopifyAdmin::getProductById($product["id"])->collect();
                            $item_detail = $item_detail_response["product"];
                            $item_tags = explode(",", $item_detail["tags"]);

                            if (in_array(ShopifyConstants::LESS_500, $item_tags)) {
                                $total_discount_from_claim_500_promo += $product["final_price"] * $product["quantity"];
                                $total_quantity_of_items_from_claim_500++;
                            }
                        });

                        $customer_current_points = $total_discount_from_claim_500_promo * -1;
                    }
                }

                if ($total_quantity_of_items_from_claim_500 > 0) {
                    $discount_name = $this->generateNameForDiscountClaim500Code(
                        $shopify_customer_id,
                        $total_quantity_of_items_from_claim_500
                    );
                }

                $shopify_response = ShopifyAdmin::getDiscountCode($discount_code);

                if ($shopify_response->ok()) {

                    // if discount code already exists, get the price rule
                    $discount_response = $shopify_response->collect();
                    $discount_price_rule_id = $discount_response['discount_code']['price_rule_id'];
                    $price_rule_response = ShopifyAdmin::getPriceRule($discount_price_rule_id);
                    $price_rule = $price_rule_response->collect();

                    if (!$price_rule_response->failed()) {
                        // if points and the amount is not the same
                        $price_rule_amount = $price_rule['price_rule']['value'];
                        if ($customer_current_points !== $price_rule_amount) {
                            // update the price rule
                            $price_rule_update_response = ShopifyAdmin::updatePriceRuleAmount(
                                $discount_price_rule_id,
                                $customer_current_points
                            );

                            if (!$price_rule_update_response->failed()) {
                                $response = [
                                    'success' => true,
                                    'discount_code' => $discount_code,
                                ];
                            } else {
                                $response = [
                                    'success' => false,
                                    'message' => 'failed to update the price rule',
                                ];
                            }
                        }
                    } else {
                        $response = [
                            'success' => false,
                            'message' => 'failed to get the price rule',
                        ];
                    }
                } else if ($shopify_response->status() === Response::HTTP_NOT_FOUND) {
                    // create a new price rule
                    $price_rule_response = ShopifyAdmin::createPriceRule(
                        $discount_name,
                        $shopify_customer_id,
                        $customer_current_points
                    );
                    $new_price_rule = $price_rule_response->collect();

                    if (!$price_rule_response->failed()) {
                        $new_discount_code_response = ShopifyAdmin::createDiscountCode(
                            $new_price_rule['price_rule']['id'],
                            $discount_code
                        );
                        if (!$new_discount_code_response->failed()) {
                            $response = [
                                'success' => true,
                                'discount_code' => $discount_code,
                            ];
                        } else {
                            $response = [
                                'success' => false,
                                'message' => 'failed to create a new discount',
                            ];
                        }
                    } else {
                        $response = [
                            'success' => false,
                            'message' => 'failed to create a new price rule',
                        ];
                    }
                } else {
                    // TODO log the error
                    $response = [
                        'success' => false,
                        'message' => 'failed to create dicount code',
                    ];
                }
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
                $customer_current_points = $customer_balance['data']['currencies'][0]['validPoints'];
                $customer_pending_points = $customer_balance['data']['currencies'][0]['pendingPoints'];

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

    private function generateNameForDiscountClaim500Code(string $customer_id, int $quantity): String
    {
        //change if they want a different naming convention for the disount code
        return ShopifyConstants::LESS_500_DISCOUNT_CODE . "_" . $customer_id . "_" . $quantity;
    }

    private function generateNameForDiscountCode(string $customer_id): String
    {
        //change if they want a different naming convention for the disount code
        return ZAPConstants::DISCOUNT_PREFIX . $customer_id;
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
}
