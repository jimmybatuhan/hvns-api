<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use App\Shopify\Constants as ShopifyConstants;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
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
        if (! $validator->fails()) {
            // generate discount code name
            $shopify_customer_id = $request->shopify_customer_id;
            $discount_code = $this->generateNameForDiscountCode($shopify_customer_id);
            $discount_name = ZAPConstants::DISCOUNT_PREFIX . $discount_code;
            // get customer balance (points)
            $zap_response = ZAP::inquireBalance($request->mobile);
            $customer_balance = $zap_response->collect();

            if (! $zap_response->failed()) {
                //TODO: Change this to for loop to get the correct currency if they have multiples
                $available_customer_points = $customer_balance['data']['currencies'][0]['validPoints'];

                //Limit the available customer points to 500
                if ($available_customer_points > ShopifyConstants::MAXIMUM_POINTS_TO_USE) {
                    $available_customer_points = ShopifyConstants::MAXIMUM_POINTS_TO_USE;
                }

                if ($request->points_to_use > $available_customer_points || $request->points_to_use < 0) {
                    $customer_current_points = strval($available_customer_points * -1);
                }else {
                    $customer_current_points = strval($request->points_to_use * -1);
                }

                $shopify_response = ShopifyAdmin::getDiscountCode($discount_code);

                if ($shopify_response->ok()) {

                    // if discount code already exists, get the price rule
                    $discount_response = $shopify_response->collect();
                    $discount_price_rule_id = $discount_response['discount_code']['price_rule_id'];
                    $price_rule_response = ShopifyAdmin::getPriceRule($discount_price_rule_id);
                    $price_rule = $price_rule_response->collect();

                    if (! $price_rule_response->failed()) {
                        // if points and the amount is not the same
                        $price_rule_amount = $price_rule['price_rule']['value'];
                        if ($customer_current_points !== $price_rule_amount) {
                            // update the price rule
                            $price_rule_update_response = ShopifyAdmin::updatePriceRuleAmount(
                                $discount_price_rule_id,
                                $customer_current_points
                            );

                            if (! $price_rule_update_response->failed()) {
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

                    if (! $price_rule_response->failed()) {
                        $new_discount_code_response = ShopifyAdmin::createDiscountCode(
                            $new_price_rule['price_rule']['id'],
                            $discount_code
                        );
                        if (! $new_discount_code_response->failed()) {
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
        if (! $validator->fails()) {
            $zap_response = ZAP::inquireBalance($request->mobile);
            $customer_balance = $zap_response->collect();

            if (! $zap_response->failed()) {
                //TODO: Change this to for loop to get the correct currency if they have multiples
                $customer_current_points = $customer_balance['data']['currencies'][0]['validPoints'];

                $response = [
                    'success' => true,
                    'available_points' => $customer_current_points,
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

    private function generateNameForDiscountCode(string $customer_id): String
    {
        //change if they want a different naming convention for the disount code
        return $customer_id;
    }
}
