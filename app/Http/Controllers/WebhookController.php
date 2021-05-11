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
                        $last_transaction_reference_reference_no = $order_metafields
                            ->lastZAPTransactionRefNo(ShopifyConstants::METAFIELD_INDEX_VALUE);

                        if ($last_transaction_reference_reference_no) {
                            $order_transaction_list = collect(json_decode($order_metafields
                                ->ZAPTransactions(ShopifyConstants::METAFIELD_INDEX_VALUE), true));
                            $last_transaction_points = floatval($order_transaction_list
                                ->filter(fn (array $transaction) =>
                                    $transaction[ZAPConstants::TRANSACTION_REFERENCE_KEY] === $last_transaction_reference_reference_no
                                )
                                ->first()[ZAPConstants::TRANSACTION_POINTS_KEY]);
                            $void_response = ZAP::deductPoints($last_transaction_points, $customer_mobile, collect([
                                'void_transaction_for' => $last_transaction_reference_reference_no,
                            ])->toJson());

                            if ($void_response->ok()) {
                                $current_balance = ZAP::inquireBalance($customer_mobile)->collect();
                                $customer_current_balance = $current_balance['data']['currencies'][0]['validPoints'];

                                if (ShopifyAdmin::updateMetafieldById(
                                        $customer_balance_metafield_id,
                                        $customer_current_balance
                                    )->failed())
                                {
                                    Log::warning("failed to update customer balance metafield", [
                                        'metafield_id' => $customer_balance_metafield_id,
                                        'value' => $customer_current_balance,
                                    ]);
                                }
                            } else {
                                $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                                $success = false;
                                Log::critical("failed to void ZAP transaction {$last_transaction_reference_reference_no}", [
                                    'order_id' => $order_id,
                                    'response' => $void_response->collect(),
                                ]);
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

                            // If transaction list not empty, decode else create a an empty collection
                            $transactions = collect($order_transaction_list
                                ? json_decode($order_transaction_list[ShopifyConstants::METAFIELD_INDEX_VALUE], true)
                                : []);

                            // Add this new transaction to the collection
                            $transactions->push([
                                ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                                ZAPConstants::TRANSACTION_POINTS_KEY => $total_points,
                                'fulfilled_at' => Carbon::now()->toIso8601String(),
                            ]);

                            if ($order_transaction_list) {
                                $last_transaction_reference_metafield_id = $order_metafields
                                    ->lastZAPTransactionRefNo(ShopifyConstants::METAFIELD_INDEX_ID);

                                $transactions_metafield_id = $order_transaction_list[ShopifyConstants::METAFIELD_INDEX_ID];

                                // Update the order's ZAP transaction list
                                if (ShopifyAdmin::updateMetafieldById(
                                        $transactions_metafield_id,
                                        $transactions->toJson()
                                    )->failed())
                                {
                                    Log::warning(
                                        "failed to update ZAP transaction lists metafield in order #{$order_id}",
                                        [
                                            'metafield_id' => $transactions_metafield_id,
                                            'value' => $transactions->toJson(),
                                        ]
                                    );
                                }

                                // Update the metafield of the order's last ZAP transaction
                                if (ShopifyAdmin::updateMetafieldById(
                                        $last_transaction_reference_metafield_id,
                                        $zap_transaction_reference_no,
                                    )->failed())
                                {
                                    Log::warning(
                                        "failed to update ZAP transaction reference metafield in order #{$order_id}",
                                        [
                                            'metafield_id' => $last_transaction_reference_metafield_id,
                                            'value' => $zap_transaction_reference_no,
                                        ]
                                    );
                                }
                            } else {
                                $order_metafields = collect()
                                    ->push([
                                        'key' => ZAPConstants::TRANSACTION_LIST_KEY,
                                        'value' => $transactions->toJson(),
                                        'value_type' => ShopifyConstants::METAFIELD_VALUE_TYPE_JSON_STRING,
                                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                    ])
                                    ->push([
                                        'key' => ZAPConstants::LAST_TRANSACTION_KEY,
                                        'value' => $zap_transaction_reference_no,
                                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                    ]);

                                if (ShopifyAdmin::addMetafields(
                                        ShopifyConstants::ORDER_RESOURCE,
                                        $order_id, $order_metafields
                                    )->failed())
                                {
                                    Log::warning(
                                        "failed to add metafields to order #{$order_id}, please add this manually",
                                        $order_metafields->toArray()
                                    );
                                }
                            }

                            // Update or add the point balance in the customer metafield
                            $new_customer_balance = $customer_balance_metafield_id
                                ? ShopifyAdmin::updateMetafieldById(
                                    $customer_balance_metafield_id,
                                    $current_customer_balance
                                )
                                : ShopifyAdmin::addMetafields(
                                    ShopifyConstants::CUSTOMER_RESOURCE,
                                    $customer_id,
                                    collect()->push([
                                        'key' => ZAPConstants::MEMBER_POINTS_KEY,
                                        'value' => $current_customer_balance,
                                        'namespace' => ZAPConstants::MEMBER_NAMESPACE
                                    ])
                                );

                            if ($new_customer_balance->failed()) {
                                Log::warning("failed to update customer balance metafield", [
                                    'customer_id' => $customer_id,
                                    'value' => $current_customer_balance,
                                ]);
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
