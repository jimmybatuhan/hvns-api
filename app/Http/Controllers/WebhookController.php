<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ShopPromo\Facades\ShopPromo;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;


class WebhookController extends Controller
{
    public function onOrderCreate(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];

        if (Arr::exists($body, 'customer')) {
            $customer = $body['customer'];
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);

            // if customer has a member id from ZAP, this means we should add a point in its account
            // else ignore the event.
            if ($customer_member_id) {
                $current_balance_response = ZAP::inquireBalance($mobile);
                $order_transaction_list = $order_metafields->ZAPTransactions();
                $customer_balance = $current_balance_response->collect();
                $customer_currencies = $customer_balance['data']['currencies'];
                $current_customer_balance = !empty($customer_currencies) ? $customer_currencies[0]['validPoints'] : 0.00;
                $transactions = collect();
                $transactions_metafield_id = null;

                // If transaction list is not empty, decode else create a an empty collection
                if ($order_transaction_list) {
                    $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
                    $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
                }

                $zap_discount = collect($body['discount_codes'])
                    ->filter(function ($discount) use ($customer_id) {
                        $code = explode("-", $discount["code"]);
                        $promo_type = $code[0] ?? null;
                        $customer = $code[1] ?? null;

                        return $customer_id == $customer
                            && (ShopifyConstants::USE_500_POINTS_PER_ITEM === $promo_type
                                || ShopifyConstants::USE_POINTS_PREFIX === $promo_type);
                    })
                    ->first();

                // if the customer used their zap points as a discount
                if ($zap_discount) {

                    $this->resetActiveDiscountCodes($customer_id);
                    $code = explode("-", $zap_discount["code"]);
                    $points_used = $code[3] ?? 0;

                    $use_points_response = ZAP::deductPoints($points_used, $mobile, "DISCOUNT CODE DEDUCTION " . $order_id);

                    if ($use_points_response->ok()) {
                        $use_points_response_body = $use_points_response->collect();
                        $zap_transaction_reference_no = $use_points_response_body['data']['refNo'];
                        $customer_balance_metafield_id = $customer_metafields
                            ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);
                        $zap_use_point_transaction = [
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $points_used,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::USE_POINT_STATUS,
                            'fulfilled_at' => Carbon::now()->addHours(8)->toIso8601String(),
                        ];

                        // Add this new transaction to the collection
                        $transactions->push($zap_use_point_transaction);

                        // Add or update the collection of transaction in the Order's metafield
                        $this->createOrUpdateTransactionMetafield(
                            $order_id,
                            $order_metafields,
                            $zap_use_point_transaction,
                            $transactions_metafield_id,
                            $transactions
                        );

                        $this->createOrUpdateCustomerBalanceMetafield(
                            $customer_id,
                            $customer_balance_metafield_id,
                            $current_customer_balance - $points_used
                        );

                        //add points to return
                        ShopifyAdmin::addMetafields(
                            ShopifyConstants::ORDER_RESOURCE,
                            $order_id,
                            collect()
                                ->push([
                                    'key' => ZAPConstants::POINTS_TO_RETURN_KEY,
                                    'value' => $points_used,
                                    'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                ])
                        );

                    } else {
                        Log::critical("failed to deduct used points of order #{$order_id}", [
                            'discount_code' => $zap_discount['code'],
                            'amount' => $points_used,
                            'customer_id' => $customer_id,
                        ]);
                    }
                } else {
                    /**
                     * compute the total points to be earned, (points will be added to zap when order is fulfilled)
                     */
                    $line_items = collect($body['line_items']);
                    $line_items_points = $line_items->map(fn (array $item) => ShopPromo::calculateInitialPointsToEarn($item));
                    $keyed_line_item_points = $line_items_points->keyBy('id');

                    //add metafield for points to earn, and points earned
                    ShopifyAdmin::addMetafields(
                        ShopifyConstants::ORDER_RESOURCE,
                        $order_id,
                        collect()
                            ->push([
                                'key' => ZAPConstants::POINTS_EARNED,
                                'value' => 0,
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                            ])
                            ->push([
                                'key' => ZAPConstants::LINE_ITEM_POINTS,
                                'value' => $keyed_line_item_points->toJson(),
                                'value_type' => ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING,
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                            ])
                    );
                }
            }
        }

        return response()->json(['success' => $success], $status);
    }

    public function onOrderUpdate(Request $request): JsonResponse
    {
        /**
         * NOTE: Refunded items that is fulfilled, should be unfulfilled to trigger the recalcualtion of points
         */
        $body = $request->all();
        $order_id = $body['id'];
        $is_cancelled = !is_null($body['cancelled_at']);
        $tags = collect(explode(",", $body['tags']));
        $customer = $body['customer'];
        $should_return_all = false;

        /** get returned items if theres any */

        try {
            $tags_trimmed = $tags->map(function ($tag) {
                return trim($tag);
            });

            //get discount codes
            $customer_id = $customer['id'];
            $zap_discount = collect($body['discount_codes'])
                    ->filter(function ($discount) use ($customer_id) {
                        $code = explode("-", $discount["code"]);
                        $promo_type = $code[0] ?? null;
                        $customer = $code[1] ?? null;

                        return $customer_id == $customer
                            && (ShopifyConstants::USE_500_POINTS_PER_ITEM === $promo_type
                                || ShopifyConstants::USE_POINTS_PREFIX === $promo_type);
                    })
                    ->first();

            $returned_items = $tags_trimmed
                ->filter(fn ($tag) => Str::contains($tag, 'RETURN'))
                ->map(function ($tag) use (&$should_return_all) {
                    $command = explode(" ", $tag);

                    /**
                     * return all items
                     */
                    if ($command[1] == 'ALL') {
                        $should_return_all = true;
                    }

                    /**
                     * it is possible that this approach will throw an undefined index
                     * but i think this is safer rather than processing an invalid command
                     * causing unwanted behavior.
                     * 
                     */
                    if ($should_return_all) {
                        return [];
                    } else {
                        return [
                            'keyword' => $command[0],
                            'id' => $command[1],
                            'total' => $command[2],
                        ];
                    }
                });

            /** if order has a order */
            if ($customer) {

                $customer_metafields = ShopifyAdmin::fetchMetafield($customer['id'], ShopifyConstants::CUSTOMER_RESOURCE);
                $customer_member_id = $customer_metafields->ZAPMemberId();

                /**
                 *  if the customer is a zap memberm and
                 *  the order doesnt not have a zap_member_order tag
                 * */

                if (($customer_member_id !== null && $customer_member_id['value'] !== 'N/A' && !empty($customer_member_id))
                    && !$tags_trimmed->contains('ZAP_MEMBER_ORDER') 
                ) {

                    Log::critical("adding tags to order #{$order_id}", [
                        "current" => $tags,
                        "contain" => $tags->contains('ZAP_MEMBER_ORDER'),
                        "customer_member_id" => $customer_member_id
                    ]);

                    $tags->push('ZAP_MEMBER_ORDER');
                    ShopifyAdmin::addTagsToOrder($order_id, $tags->implode(','));
                }
            }

            if ($is_cancelled) {
                return response()->json(['success' => true], Response::HTTP_OK);
            }  else if ($zap_discount && $should_return_all) {
                    
                $code = explode("-", $zap_discount["code"]);
                $points_used = $code[2] ?? 0;

                $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
                $points_to_return_metafield = $order_metafields->getPointsToReturnMetafieldId();
                $points_to_return = $order_metafields->getPointsToReturnMetafield();

                if($points_to_return > 0){
                    $this->customerRewardPoints($body, $points_used, ZAPConstants::RETURNED_POINT_STATUS);
                    ShopifyAdmin::updateMetafieldById(
                        $points_to_return_metafield,
                        0
                    );
                }

                return response()->json(['success' => true], Response::HTTP_OK);

            } else {
                $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);

                $points_earned_metafield = $order_metafields->getPointsEarnedMetafieldId();
                $points_earned = $order_metafields->getPointsEarnedMetafield();

                $line_item_points_metafield = $order_metafields->getLineItemPointsMetafieldId();
                $line_item_points = $order_metafields->getLineItemPointsMetafield();
                $line_item_points_original_count = is_countable($line_item_points) ? count($line_item_points) : 0;

                /**
                 * if the order is not cancelled and has a points_to_earn metafield
                 * recalculate the points to be earn
                 */
                if ($points_earned_metafield) {
                    $fulfilled_line_items = collect([]);

                    collect($body['fulfillments'])
                        ->filter(fn (array $fulfillment) => $fulfillment['status'] === 'success')
                        ->each(function (array $fulfillment) use ($returned_items, $should_return_all, &$fulfilled_line_items) {
                            collect($fulfillment['line_items'])
                                /** collect line items that will be calculated */
                                ->each(function (array $line_item) use ($returned_items, $should_return_all, &$fulfilled_line_items) {
                                    $line_item['original_quantity'] = $line_item['quantity'];
                                    $variant_id = $line_item['variant_id'];

                                    if ($should_return_all) {
                                        $line_item['quantity'] = 0;
                                    } else {
                                        $returned = $returned_items
                                            ->filter(fn ($returned_item) => $returned_item['id'] == $variant_id)
                                            ->first();
                                        if ($returned) {
                                            $line_item['quantity'] = max($line_item['quantity'] - $returned['total'], 0);
                                        }
                                    }

                                    $fulfilled_line_items->push($line_item);
                                });
                        });

                    $total_points_collection = $fulfilled_line_items->map(function (array $item) use (&$line_item_points) {
                        $result = ShopPromo::calculatePointsToEarn($item, $line_item_points);
                        if (!array_key_exists($result['id'], $line_item_points)) {
                            $line_item_points[$result['id']] = [
                                "id" => $result['id'],
                                "variant_id" => $item['variant_id'],
                                "reward_type" => $result['reward_type'],
                                "original_quantity" => $item['original_quantity'],
                                "calculated_quantity" => $item["quantity"],
                                "reward_amount" => $result['reward_amount'],
                                "points_to_earn" => $result['points_to_earn'],
                            ];
                        }

                        return $result;
                    });

                    $line_item_points_new_count = count($line_item_points);

                    $total_points_to_earn = $total_points_collection->sum('points_to_credit');
                    $total_subtotal_amount = $total_points_collection->sum('subtotal_amount');
                    $line_item_points_collection = collect($line_item_points);

                    $total_points_to_earn = round($total_points_to_earn, 2);

                    if ($total_points_to_earn != floatval($points_earned)) {

                        if ($points_earned > 0) {
                            $this->customerDeductZAPPoints($body, $points_earned);
                        }

                        if (
                            floatval($total_subtotal_amount) >= ShopifyConstants::MINIMUM_SUBTOTAL_TO_EARN &&
                            $total_points_to_earn > 0
                        ) {
                            $this->customerRewardPoints($body, $total_points_to_earn);
                        }

                        Log::critical("updating points to earn to order #{$order_id}", [
                            'points' => $total_points_to_earn,
                            'old_points' => floatval($points_earned),
                            'metafield' => $points_earned_metafield,
                        ]);

                        ShopifyAdmin::updateMetafieldById($points_earned_metafield, $total_points_to_earn);
                    }

                    if ($line_item_points_new_count > $line_item_points_original_count) {

                        Log::critical("updating line item points to earn to order #{$order_id}", [
                            'metafield' => $line_item_points_metafield,
                            'new_line_item_count' => $line_item_points_new_count,
                            'old_line_item_count' => $line_item_points_original_count,
                        ]);

                        ShopifyAdmin::updateMetafieldById($line_item_points_metafield, $line_item_points_collection->toJson());
                    }
                }

                return response()->json(['success' => true, "err_message" => null], Response::HTTP_OK);
            }
        } catch (\Throwable $th) {
            Log::critical($th->getMessage(), [
                'trace' => $th->getTraceAsString(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);
            return response()->json([
                'success' => true,
                'err_message' => $th->getMessage(),
            ], Response::HTTP_OK);
        }
    }

    public function onOrderCanceled(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];
        $customer = $body['customer'];
        $dicount_codes = $body['discount_codes'];
        $line_items = collect($body['line_items']);

        if (Arr::exists($body, 'customer')) {
            $customer = $body['customer'];
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);
            $zap_discount = collect($dicount_codes)
                ->filter(fn ($discount) => $discount['code'] === ZAPConstants::DISCOUNT_PREFIX . $customer_id)
                ->first();

            // if customer has a member id from ZAP, this means we should do the next process
            // else ignore the event.

            $has_some_fulfilled = $line_items->filter(fn (array $item) => $item['fulfillment_status'] === 'fulfilled')->count() > 0;

            if ($customer_member_id) {
                $order_transaction_list = $order_metafields->ZAPTransactions();
                $transactions = collect([]);
                $transactions_metafield_id = null;

                // If transaction list is not empty, decode else create a an empty collection
                if ($order_transaction_list) {
                    $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
                    $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
                }

                // if the customer used their zap points as a discount, if order is cancelled, we will return the points
                if ($zap_discount && !$has_some_fulfilled) {
                    $points_used = $zap_discount['amount'];
                    $use_points_response = ZAP::addPoints($points_used, $mobile, $order_id);

                    if ($use_points_response->ok()) {
                        $use_points_response_body = $use_points_response->collect();
                        $zap_transaction_reference_no = $use_points_response_body['data']['refNo'];
                        $zap_return_point_transaction = [
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $points_used,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::RETURNED_POINT_STATUS,
                            'fulfilled_at' => Carbon::now()->addHours(8)->toIso8601String(),
                        ];

                        // Add this new transaction to the collection
                        $transactions->push($zap_return_point_transaction);

                        // Add or update the collection of transaction in the Order's metafield
                        $this->createOrUpdateTransactionMetafield(
                            $order_id,
                            $order_metafields,
                            $zap_return_point_transaction,
                            $transactions_metafield_id,
                            $transactions
                        );
                    } else {
                        Log::critical("failed to add used points of cancelled order #{$order_id}", [
                            'discount_code' => $zap_discount['code'],
                            'amount' => $points_used,
                            'customer_id' => $customer_id,
                        ]);
                    }
                }
            } else {
                Log::warning("order #{$order_id} has been cancelled without a zap member information");
            }
        } else {
            Log::warning("order #{$order_id} has been cancelled without a customer information");
        }

        return response()->json(['success' => $success], $status);
    }

    private function createOrUpdateCustomerBalanceMetafield(
        string $customer_id,
        string $balance_metafield_id,
        string $customer_balance
    ): void {
        // Update or add the point balance in the customer metafield
        if ($balance_metafield_id) {
            ShopifyAdmin::updateMetafieldById(
                $balance_metafield_id,
                $customer_balance
            );
        } else {
            ShopifyAdmin::addMetafields(
                ShopifyConstants::CUSTOMER_RESOURCE,
                $customer_id,
                collect()->push([
                    'key' => ZAPConstants::MEMBER_POINTS_KEY,
                    'value' => $customer_balance,
                    'namespace' => ZAPConstants::MEMBER_NAMESPACE
                ])
            );
        }
    }

    private function createOrUpdateTransactionMetafield(
        string $order_id,
        ClientResponse $order_metafields,
        array $last_zap_transaction,
        ?string $transactions_metafield_id,
        Collection $transactions
    ): void {
        $json_transactions = $transactions->toJson();

        if ($transactions_metafield_id) {
            $last_transaction_reference_metafield_id = $order_metafields
                ->lastZAPTransactionRefNo(ShopifyConstants::METAFIELD_INDEX_ID);

            // Update the order's ZAP transaction list
            ShopifyAdmin::updateMetafieldById(
                $transactions_metafield_id,
                $json_transactions,
                ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING
            );

            // Update the metafield of the order's last ZAP transaction
            ShopifyAdmin::updateMetafieldById(
                $last_transaction_reference_metafield_id,
                json_encode($last_zap_transaction),
                ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING
            );
        } else {
            ShopifyAdmin::addMetafields(
                ShopifyConstants::ORDER_RESOURCE,
                $order_id,
                collect()
                    ->push([
                        'key' => ZAPConstants::TRANSACTION_LIST_KEY,
                        'value' => $json_transactions,
                        'value_type' => ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING,
                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                    ])
                    ->push([
                        'key' => ZAPConstants::LAST_TRANSACTION_KEY,
                        'value' => json_encode($last_zap_transaction),
                        'value_type' => ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING,
                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                    ])
            );
        }
    }

    private function customerRewardPoints(array $payload, float $amount, bool $status = ZAPConstants::EARN_POINT_STATUS): void
    {
        $order_id = $payload['id'];
        $customer = $payload['customer'];
        $customer_id = $customer['id'];
        $mobile = substr($customer['phone'], 1);

        $transactions = collect();
        $add_points_request = ZAP::addPoints($amount, $mobile, $order_id);
        $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
        $transactions_metafield_id = null;
        $order_transaction_list = $order_metafields->ZAPTransactions();

        // If transaction list is not empty, decode else create a an empty collection
        if ($order_transaction_list) {
            $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
            $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
        }

        if ($add_points_request->ok()) {
            $add_points_response = $add_points_request->collect();
            $zap_transaction_reference_no = $add_points_response['data']['refNo'];
            $zap_return_point_transaction = [
                ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                ZAPConstants::TRANSACTION_POINTS_KEY => $amount,
                ZAPConstants::TRANSACTION_STATUS_KEY => $status,
                'fulfilled_at' => Carbon::now()->addHours(8)->toIso8601String(),
            ];

            // Add this new transaction to the collection
            $transactions->push($zap_return_point_transaction);

            // Add or update the collection of transaction in the Order's metafield
            $this->createOrUpdateTransactionMetafield(
                $order_id,
                $order_metafields,
                $zap_return_point_transaction,
                $transactions_metafield_id,
                $transactions
            );

            Log::critical("adding reward points #{$order_id}");

        } else {
            Log::critical("failed to add used points of cancelled order #{$order_id}", [
                'amount' => $amount,
                'customer_id' => $customer_id,
            ]);
        }
    }

    private function customerDeductZAPPoints(array $payload, float $amount): void
    {
        $order_id = $payload['id'];
        $customer = $payload['customer'];
        $customer_id = $customer['id'];
        $mobile = substr($customer['phone'], 1);
        $transactions = collect();
        $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
        $transactions_metafield_id = null;
        $order_transaction_list = $order_metafields->ZAPTransactions();

        // If transaction list is not empty, decode else create a an empty collection
        if ($order_transaction_list) {
            $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
            $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
        }

        $deduct_points_request = ZAP::deductPoints($amount, $mobile, $order_id);

        if ($deduct_points_request->ok()) {
            $deduct_points_response_body = $deduct_points_request->collect();
            $zap_transaction_reference_no = $deduct_points_response_body['data']['refNo'];

            $deduct_points_transaction = [
                ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                ZAPConstants::TRANSACTION_POINTS_KEY => $amount,
                ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::VOID_POINT_STATUS,
                'deducted_at' => Carbon::now()->addHours(8)->toIso8601String(),
            ];

            // Add this new transaction to the collection
            $transactions->push($deduct_points_transaction);

            // Add or update the collection of transaction in the Order's metafield
            $this->createOrUpdateTransactionMetafield(
                $order_id,
                $order_metafields,
                $deduct_points_transaction,
                $transactions_metafield_id,
                $transactions
            );

            Log::critical("deducting reward points #{$order_id}");
        } else {
            Log::critical("failed to deduct used points of order #{$order_id}", [
                'amount' => $amount,
                'customer_id' => $customer_id,
            ]);
        }
    }

    private function resetActiveDiscountCodes(string $shopify_customer_id): bool
    {
        try {
            $metafields = ShopifyAdmin::fetchMetafield($shopify_customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $active_discount_code_id = $metafields->ActiveDiscountCodeId();
            $active_discount_code = collect();
            $active_discount_code->push([
                "key" => "last_active_discount",
                "namespace" => ZAPConstants::MEMBER_NAMESPACE,
                "value" => "N/A",
            ]);

            if (empty($active_discount_code_id)) {
                ShopifyAdmin::addMetafields(
                    ShopifyConstants::CUSTOMER_RESOURCE,
                    $shopify_customer_id,
                    $active_discount_code
                );
            } else {
                $current_active_discount = $metafields->ActiveDiscountCode();                
                ShopifyAdmin::updateMetafieldById($active_discount_code_id["id"], "N/A");
            }
        } catch (Exception $e) {
            report($e);
            return false;
        }

        return true;
    }
}
