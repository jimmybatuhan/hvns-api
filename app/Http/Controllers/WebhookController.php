<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ShopPromo\Facades\ShopPromo;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

                    // if the customer is a ZAP member, void the transaction
                    if ($customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE)) {
                        $last_zap_transaction = $order_metafields->lastZAPTransaction();
                        $last_zap_trans_status = $last_zap_transaction[ZAPConstants::TRANSACTION_STATUS_KEY];

                        if ($last_zap_transaction) {
                            /**
                             * Prevent the system from deducting points from previously deducted order,
                             * this should not happend, but will prevent it anyway just in case.
                             */
                            if ($last_zap_trans_status != ZAPConstants::VOID_POINT_STATUS) {
                                $order_transaction_list = collect($order_metafields->transactionList());
                                $last_transaction_points = $last_zap_transaction[ZAPConstants::TRANSACTION_POINTS_KEY];
                                $last_trans_meta_id = $order_metafields->lastZAPTransactionMetaId();
                                $trans_list_meta_id = $order_metafields->ZAPTransactionListMetaId();
                                $void_response = ZAP::deductPoints($last_transaction_points, $customer_mobile);

                                if ($void_response->ok()) {
                                    $current_balance = ZAP::inquireBalance($customer_mobile)->collect();
                                    $customer_current_balance = $current_balance['data']['currencies'][0]['validPoints'];
                                    $void_trans_body = $void_response->collect();
                                    $void_trans_ref_no = $void_trans_body['data']['refNo'];

                                    $zap_new_transaction = [
                                        ZAPConstants::TRANSACTION_REFERENCE_KEY => $void_trans_ref_no,
                                        ZAPConstants::TRANSACTION_POINTS_KEY => $last_transaction_points,
                                        ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::VOID_POINT_STATUS,
                                        'fulfilled_at' => Carbon::now()->toIso8601String()
                                    ];

                                    $order_transaction_list->push($zap_new_transaction);

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
                                } else {
                                    $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                                    $success = false;
                                    Log::critical("failed to void ZAP transaction {$last_ref_no}", [
                                        'order_id' => $order_id,
                                        'response' => $void_response->collect(),
                                    ]);
                                }
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

    public function onOrderFulfilled(Request $request): JsonResponse
    {
        $success = true;
        $status = Response::HTTP_OK;
        $body = $request->all();
        $order_id = $body['id'];
        $customer = $body['customer'];
        $line_items = collect($body['line_items']);
        $metafields = collect([
            'order_id' => $order_id,
            'sub_total' => floatval($body['current_subtotal_price']),
        ]);
        $current_subtotal_price = $body['current_subtotal_price'];

        if (floatval($current_subtotal_price) >= ShopifyConstants::MINIMUM_SUBTOTAL_TO_EARN) {
            if (Arr::exists($body, 'customer')) {
                $customer = $body['customer'];
                $customer_id = $customer['id'];
                $mobile = substr($customer['phone'], 1);
                $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
                $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
                $customer_member_id = $customer_metafields->ZAPMemberId(ShopifyConstants::METAFIELD_INDEX_VALUE);
                $customer_balance_metafield_id = $customer_metafields
                    ->ZAPMemberTotalPoints(ShopifyConstants::METAFIELD_INDEX_ID);

                // if customer has a member id from ZAP, this means we should add a point in its account
                // else ignore the event.
                if ($customer_member_id) {
                    $total_points = $line_items->map(fn (array $item) => ShopPromo::calculatePoints($item))->sum();
                    $zap_add_points_response = ZAP::addPoints($total_points, $mobile, $metafields->toJson());
                    $current_balance_response = ZAP::inquireBalance($mobile);

                    if ($current_balance_response->ok()) {
                        $customer_balance = $current_balance_response->collect();
                        $current_customer_balance = $customer_balance['data']['currencies'][0]['validPoints'];

                        if ($zap_add_points_response->ok()) {
                            $add_points_response_body = $zap_add_points_response->collect();
                            $zap_transaction_reference_no = $add_points_response_body['data']['refNo'];
                            $order_transaction_list = $order_metafields->ZAPTransactions();
                            $zap_new_transaction = [
                                ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                                ZAPConstants::TRANSACTION_POINTS_KEY => $total_points,
                                ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::EARN_POINT_STATUS,
                                'fulfilled_at' => Carbon::now()->toIso8601String(),
                            ];

                            // If transaction list is not empty, decode else create a an empty collection
                            $transactions = collect($order_transaction_list
                                ? json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true)
                                : []);

                            // Add this new transaction to the collection
                            $transactions->push($zap_new_transaction);
                            $json_transactions = $transactions->toJson();

                            if ($order_transaction_list) {
                                $last_transaction_reference_metafield_id = $order_metafields
                                    ->lastZAPTransactionRefNo(ShopifyConstants::METAFIELD_INDEX_ID);

                                $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];

                                // Update the order's ZAP transaction list
                                ShopifyAdmin::updateMetafieldById(
                                    $transactions_metafield_id,
                                    $json_transactions,
                                    ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING
                                );

                                // Update the metafield of the order's last ZAP transaction
                                ShopifyAdmin::updateMetafieldById(
                                    $last_transaction_reference_metafield_id,
                                    json_encode($zap_new_transaction),
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
                                            'value' => json_encode($zap_new_transaction),
                                            'value_type' => ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING,
                                            'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                        ])
                                );
                            }

                            // Update or add the point balance in the customer metafield
                            if ($customer_balance_metafield_id) {
                                ShopifyAdmin::updateMetafieldById(
                                    $customer_balance_metafield_id,
                                    $current_customer_balance
                                );
                            } else {
                                ShopifyAdmin::addMetafields(
                                    ShopifyConstants::CUSTOMER_RESOURCE,
                                    $customer_id,
                                    collect()->push([
                                        'key' => ZAPConstants::MEMBER_POINTS_KEY,
                                        'value' => $current_customer_balance,
                                        'namespace' => ZAPConstants::MEMBER_NAMESPACE
                                    ])
                                );
                            }
                        } else {
                            $success = false;
                            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
                            Log::critical("failed to add points to fulfilled order #{$order_id}", [
                                'zap_add_points_response' => $zap_add_points_response->json(),
                                'transaction_metafields' => $metafields->toJson(),
                                'calculated_points' => $total_points,
                                'customer_id' => $customer_id,
                            ]);
                        }
                    } else {
                        $success = false;
                        $status = Response::HTTP_INTERNAL_SERVER_ERROR;
                        Log::critical("failed to get customer points to fulfilled order #{$order_id}", [
                            'mobile' => $mobile,
                            'customer_id' => $customer_id,
                        ]);
                    }
                } else {
                    Log::warning("order #{$order_id} has been fulfilled without a zap member information");
                }
            } else {
                Log::warning("order #{$order_id} has been fulfilled without a customer information");
            }
        } else {
            Log::warning("fulfilled order #{$order_id} did not meet the minimum subtotal to earn points");
        }

        return response()->json(['success' => $success], $status);
    }
}
