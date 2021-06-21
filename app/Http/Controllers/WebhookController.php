<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ShopPromo\Facades\ShopPromo;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function onFulfillmentUpdate(Request $request): JsonResponse
    {
        $response_status = Response::HTTP_OK;
        $success = true;
        $body = $request->all();
        $fulfillment_id = $body['id'];
        $order_id = $body['order_id'];
        $fulfillment_status = $body['status'];
        $order_resource_response = ShopifyAdmin::getOrderById($order_id);
        $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);

        if ($order_resource_response->ok()) {
            $order_resource = $order_resource_response->collect()['order'];

            // Revoke points earned if the fulfillment has been cancelled
            // and the customer is a ZAP member
            if ($fulfillment_status === ShopifyConstants::FULFILLMENT_CANCELLED) {

                // If the order has a customer
                if (Arr::exists($order_resource, 'customer')) {
                    $customer_resource = $order_resource['customer'];
                    $customer_id = $customer_resource['id'];
                    $customer_mobile = substr($customer_resource['phone'], 1);
                    $customer_metafields = ShopifyAdmin::fetchMetafield(
                        $customer_id,
                        ShopifyConstants::CUSTOMER_RESOURCE
                    );
                    $customer_balance_metafield_id = $customer_metafields
                        ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);
                    $discount_codes = $order_resource['discount_codes'];
                    $zap_discount = collect($discount_codes)
                        ->filter(fn ($discount) => $discount['code'] === ZAPConstants::DISCOUNT_PREFIX . $customer_id)
                        ->first();

                    // if the customer is a ZAP member, void the transaction
                    if ($customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE)) {
                        $last_zap_transaction = $order_metafields->lastZAPTransaction();
                        $last_zap_trans_status = $last_zap_transaction[ZAPConstants::TRANSACTION_STATUS_KEY];
                        $order_transaction_list = collect($order_metafields->transactionList());
                        $last_transaction_points = $last_zap_transaction[ZAPConstants::TRANSACTION_POINTS_KEY];
                        $last_trans_meta_id = $order_metafields->lastZAPTransactionMetaId();
                        $trans_list_meta_id = $order_metafields->ZAPTransactionListMetaId();
                        $current_balance = ZAP::inquireBalance($customer_mobile)->collect();
                        $customer_current_balance = $current_balance['data']['currencies'][0]['validPoints'];
                        $zap_new_transaction = [];

                        if ($last_zap_transaction) {
                            if ($zap_discount) {
                                $points_used = $zap_discount['amount'];
                                $zap_return_points_response = ZAP::addPoints($points_used, $customer_mobile);

                                if ($zap_return_points_response->ok()) {
                                    $return_points_body = $zap_return_points_response->collect();
                                    $return_points_trans_ref_no = $return_points_body['data']['refNo'];
                                    $zap_new_transaction = [
                                        ZAPConstants::TRANSACTION_REFERENCE_KEY => $return_points_trans_ref_no,
                                        ZAPConstants::TRANSACTION_POINTS_KEY => $last_transaction_points,
                                        ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::RETURNED_POINT_STATUS,
                                        'fulfilled_at' => Carbon::now()->toIso8601String()
                                    ];

                                    $order_transaction_list->push($zap_new_transaction);
                                } else {
                                    $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                                    $success = false;
                                    Log::critical("failed to return ZAP points of order {$order_id}", [
                                        'order_id' => $order_id,
                                        'response' => $zap_return_points_response->collect(),
                                    ]);
                                }
                            } else {
                                /**
                                 * Prevent the system from deducting points from previously deducted order,
                                 * this should not happend, but will prevent it anyway just in case.
                                 */
                                if ($last_zap_trans_status != ZAPConstants::VOID_POINT_STATUS) {
                                    $void_response = ZAP::deductPoints($last_transaction_points, $customer_mobile);

                                    if ($void_response->ok()) {
                                        $void_trans_body = $void_response->collect();
                                        $void_trans_ref_no = $void_trans_body['data']['refNo'];
                                        $zap_new_transaction = [
                                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $void_trans_ref_no,
                                            ZAPConstants::TRANSACTION_POINTS_KEY => $last_transaction_points,
                                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::VOID_POINT_STATUS,
                                            'fulfilled_at' => Carbon::now()->toIso8601String()
                                        ];

                                        $order_transaction_list->push($zap_new_transaction);
                                    } else {
                                        $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                                        $success = false;
                                        Log::critical("failed to void ZAP transaction of order {$order_id}", [
                                            'order_id' => $order_id,
                                            'response' => $void_response->collect(),
                                        ]);
                                    }
                                }
                            }

                            if ($success && ! empty($zap_new_transaction)) {
                                // Update the last zap transaction in the metafield of the order
                                ShopifyAdmin::updateMetafieldById(
                                    $last_trans_meta_id,
                                    json_encode($zap_new_transaction),
                                    ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING
                                );
                                // Update the order's transaction list
                                ShopifyAdmin::updateMetafieldById(
                                    $trans_list_meta_id,
                                    $order_transaction_list->toJson(),
                                    ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING
                                );
                                // Update the customer balance metafield
                                ShopifyAdmin::updateMetafieldById(
                                    $customer_balance_metafield_id,
                                    $customer_current_balance
                                );
                            }
                        }
                    }
                }
            }
        } else {
            $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $success = false;
            Log::critical("failed to get order resource of fulfillment #{$fulfillment_id}", [
                'order_id' => $order_id
            ]);
        }

        return response()->json(['success' => $success], $response_status);
    }

    public function onOrderCreate(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];
        $metafields = collect([
            'order_id' => $order_id,
            'sub_total' => floatval($body['subtotal_price']),
        ]);

        if (Arr::exists($body, 'customer')) {
            $customer = $body['customer'];
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);
            $zap_discount = collect($body['discount_codes'])
                ->filter(fn ($discount) => $discount['code'] === ZAPConstants::DISCOUNT_PREFIX . $customer_id)
                ->first();

            // if customer has a member id from ZAP, this means we should add a point in its account
            // else ignore the event.
            if ($customer_member_id) {
                $current_balance_response = ZAP::inquireBalance($mobile);
                $order_transaction_list = $order_metafields->ZAPTransactions();
                $customer_balance = $current_balance_response->collect();
                $customer_currencies = $customer_balance['data']['currencies'];
                $current_customer_balance = ! empty($customer_currencies) ? $customer_currencies[0]['validPoints'] : 0.00;
                $transactions = collect();
                $transactions_metafield_id = null;

                // If transaction list is not empty, decode else create a an empty collection
                if ($order_transaction_list) {
                    $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
                    $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
                }

                // if the customer used their zap points as a discount
                if ($zap_discount) {
                    $points_used = $zap_discount['amount'];
                    $use_points_response = ZAP::deductPoints($points_used, $mobile);

                    if ($use_points_response->ok()) {
                        $use_points_response_body = $use_points_response->collect();
                        $zap_transaction_reference_no = $use_points_response_body['data']['refNo'];
                        $customer_balance_metafield_id = $customer_metafields
                            ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);
                        $zap_use_point_transaction = [
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $points_used,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::USE_POINT_STATUS,
                            'fulfilled_at' => Carbon::now()->toIso8601String(),
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
                    } else {
                        Log::critical("failed to deduct used points of order #{$order_id}", [
                            'discount_code' => $zap_discount['code'],
                            'amount' => $points_used,
                            'customer_id' => $customer_id,
                        ]);
                    }
                } else {
                    if (floatval($body['subtotal_price']) >= ShopifyConstants::MINIMUM_SUBTOTAL_TO_EARN) {
                        /**
                         * compute the total points to be earned, (points will be added to zap when order is fulfilled)
                         */
                        $line_items = collect($body['line_items']);

                        ShopifyAdmin::addMetafields(
                            ShopifyConstants::ORDER_RESOURCE,
                            $order_id,
                            collect()->push([
                                'key' => ZAPConstants::POINTS_TO_EARN_KEY,
                                'value' => $line_items->map(fn (array $item) => ShopPromo::calculatePoints($item))->sum(),
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                            ])
                        );
                    }
                }
            }
        }

        return response()->json(['success' => $success], $status);
    }

    public function onOrderCanceled(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];
        $customer = $body['customer'];
        $dicount_codes = $body['discount_codes'];

        if (Arr::exists($body, 'customer')) {
            $customer = $body['customer'];
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);
            $customer_balance_metafield_id = $customer_metafields
                ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);
            $zap_discount = collect($dicount_codes)
                ->filter( fn ($discount) => $discount['code'] === ZAPConstants::DISCOUNT_PREFIX . $customer_id)
                ->first();

            // if customer has a member id from ZAP, this means we should do the next process
            // else ignore the event.
            if ($customer_member_id) {
                $order_transaction_list = $order_metafields->ZAPTransactions();
                $transactions = collect([]);
                $transactions_metafield_id = null;
                $last_zap_transaction = $order_metafields->lastZAPTransaction();

                if ($last_zap_transaction) {
                    $last_zap_trans_status = $last_zap_transaction[ZAPConstants::TRANSACTION_STATUS_KEY];
                    $last_transaction_points = $last_zap_transaction[ZAPConstants::TRANSACTION_POINTS_KEY];
                }

                $last_trans_meta_id = $order_metafields->lastZAPTransactionMetaId();

                // If transaction list is not empty, decode else create a an empty collection
                if ($order_transaction_list) {
                    $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
                    $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
                }
                // if the customer used their zap points as a discount, if order is cancelled, we will return the points
                if ($zap_discount) {
                    $points_used = $zap_discount['amount'];
                    $use_points_response = ZAP::addPoints($points_used, $mobile);

                    if ($use_points_response->ok()) {
                        $use_points_response_body = $use_points_response->collect();
                        $zap_transaction_reference_no = $use_points_response_body['data']['refNo'];
                        $zap_return_point_transaction = [
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $points_used,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::RETURNED_POINT_STATUS,
                            'fulfilled_at' => Carbon::now()->toIso8601String(),
                        ];

                        // Add this new transaction to the collection
                        $transactions->push($zap_return_point_transaction);

                        // Add or update the collection of transaction in the Order's metafield
                        $this->createOrUpdateTransactionMetafield(
                            $order_id,
                            $order_metafields,
                            $zap_use_point_transaction,
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

                //if user has been awarded points on this order, deduct it
                if ($last_zap_transaction) {
                    /**
                     * Prevent the system from deducting points from previously deducted order,
                     * this should not happend, but will prevent it anyway just in case.
                     */
                    if ($last_zap_trans_status != ZAPConstants::VOID_POINT_STATUS) {
                        $void_response = ZAP::deductPoints($last_transaction_points, $mobile);

                        if ($void_response->ok()) {
                            $void_trans_body = $void_response->collect();
                            $void_trans_ref_no = $void_trans_body['data']['refNo'];
                            $zap_new_transaction = [
                                ZAPConstants::TRANSACTION_REFERENCE_KEY => $void_trans_ref_no,
                                ZAPConstants::TRANSACTION_POINTS_KEY => $last_transaction_points,
                                ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::VOID_POINT_STATUS,
                                'fulfilled_at' => Carbon::now()->toIso8601String()
                            ];

                            $transactions->push($zap_new_transaction);

                            // Add or update the collection of transaction in the Order's metafield
                            $this->createOrUpdateTransactionMetafield(
                                $order_id,
                                $order_metafields,
                                $zap_new_transaction,
                                $transactions_metafield_id,
                                $transactions
                            );

                        } else {
                            $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                            $success = false;
                            Log::critical("failed to void ZAP transaction of order {$order_id}", [
                                'order_id' => $order_id,
                                'response' => $void_response->collect(),
                            ]);
                        }
                    }
                }

                //get current points balance and update
                $current_balance_response = ZAP::inquireBalance($mobile);
                $customer_balance = $current_balance_response->collect();
                $current_customer_balance = $customer_balance['data']['currencies'][0]['validPoints'];

                $this->createOrUpdateCustomerBalanceMetafield(
                    $customer_id,
                    $customer_balance_metafield_id,
                    $current_customer_balance
                );


            } else {
                Log::warning("order #{$order_id} has been cancelled without a zap member information");
            }
        } else {
            Log::warning("order #{$order_id} has been cancelled without a customer information");
        }

        return response()->json(['success' => $success], $status);
    }

    public function onOrderUpdate(Request $request): JsonResponse
    {
        $body = $request->all();
        $order_id = $body['id'];
        $line_items = collect($body['line_items']);
        $cancelled_at = $body['cancelled_at'];
        $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
        $points_to_earn_metafield_id = $order_metafields->getPointsToEarnMetafieldId();

        /**
         * if the order is not cancelled and has a points_to_earn metafield
         * recalculate the points to be earn
         */
        if (is_null($cancelled_at) && $points_to_earn_metafield_id) {
            $total_points = $line_items->map(fn (array $item) => ShopPromo::calculatePoints($item))->sum();
            ShopifyAdmin::updateMetafieldById($points_to_earn_metafield_id, $total_points);
        }

        return response()->json(['success' => true], Response::HTTP_OK);
    }

    public function onOrderFulfill(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];
        $customer = $body['customer'];
        $fulfillment_status = $body['fulfillment_status'];
        $dicount_codes = $body['discount_codes'];

        if (Arr::exists($body, 'customer')) {
            $customer = $body['customer'];
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);
            $customer_balance_metafield_id = $customer_metafields
                ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);
            $zap_discount = collect($dicount_codes)
                ->filter( fn ($discount) => $discount['code'] === ZAPConstants::DISCOUNT_PREFIX . $customer_id)
                ->first();

            // if customer has a member id from ZAP, this means we should add a point in its account
            // else ignore the event.
            if ($customer_member_id) {
                $order_transaction_list = $order_metafields->ZAPTransactions();
                $transactions = collect([]);
                $transactions_metafield_id = null;
                $calculated_points = $order_metafields->getPointsToEarnMetafield();
                $last_zap_transaction = $order_metafields->lastZAPTransaction();
                $award_points_flag = true;

                // If transaction list is not empty, decode else create a an empty collection
                if ($order_transaction_list) {
                    $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];
                    $transactions = collect(json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true));
                }

                if ($zap_discount) {
                    $award_points_flag = false;
                    Log::warning("order #{$order_id} has been fulfilled but not awarded as it has discount codes");
                } else if (! $calculated_points) {
                    $award_points_flag = false;
                    Log::warning("order #{$order_id} has been fulfilled without a calculated points");
                } else if ($fulfillment_status !== ShopifyConstants::FULFILLMENT_FULFILLED) {
                    $award_points_flag = false;
                } else if ($last_zap_transaction) {
                    $last_zap_trans_status = $last_zap_transaction[ZAPConstants::TRANSACTION_STATUS_KEY];

                    if ($last_zap_trans_status == ZAPConstants::EARN_POINT_STATUS) {
                        $award_points_flag = false;
                        Log::warning("order #{$order_id} has been fulfilled though points is already earned");
                    }
                }

                if ($award_points_flag) {

                    $earn_points_response = ZAP::addPoints($calculated_points, $mobile);

                    if ($earn_points_response->ok()) {
                        $earn_points_response_body = $earn_points_response->collect();
                        $zap_transaction_reference_no = $earn_points_response_body['data']['refNo'];
                        $zap_use_point_transaction = [
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $calculated_points,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::EARN_POINT_STATUS,
                            'fulfilled_at' => Carbon::now()->toIso8601String(),
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

                        //get current points balance and update
                        $current_balance_response = ZAP::inquireBalance($mobile);
                        $customer_balance = $current_balance_response->collect();
                        $current_customer_balance = $customer_balance['data']['currencies'][0]['validPoints'];

                        $this->createOrUpdateCustomerBalanceMetafield(
                            $customer_id,
                            $customer_balance_metafield_id,
                            $current_customer_balance
                        );

                    } else {
                        Log::critical("failed to award used points of fulfilled order #{$order_id}", [
                            'discount_code' => $zap_discount['code'],
                            'amount' => $points_used,
                            'customer_id' => $customer_id,
                        ]);
                    }
                }

            } else {
                Log::warning("order #{$order_id} has been fulfilled without a zap member information");
            }
        } else {
            Log::warning("order #{$order_id} has been fulfilled without a customer information");
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
}
