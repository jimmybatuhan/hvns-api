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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function onFulfillmentUpdate(Request $request): JsonResponse
    {
        $response_status = Response::HTTP_OK;
        $success = true;
        $body = $request->all();
        $order_id = $body['order_id'];
        $fulfillment_status = $body['status'];

        $shopify_response = ShopifyAdmin::fetchMetafield(
            $order_id,
            ShopifyConstants::ORDER_RESOURCE
        );

        if ($shopify_response->ok()) {
            $transaction_reference_no = $shopify_response->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_REFERENCE_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            );
            $transaction_status_meta_id = $shopify_response->metafield(
                ZAPConstants::TRANSACTION_NAMESPACE,
                ZAPConstants::TRANSACTION_STATUS_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID
            );

            /**
             * Revoke points earned if the fulfillment has been cancelled
             */
            if ($fulfillment_status === ShopifyConstants::FULFILLMENT_CANCELLED) {
                if (ZAP::voidTransaction($transaction_reference_no)->ok()) {
                    $shopify_update_response = ShopifyAdmin::updateMetafieldById(
                        $transaction_status_meta_id,
                        ZAPConstants::TRANSACTION_STATUS_VOIDED
                    );
                    if (! $shopify_update_response->ok()) {
                        $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                        $success = false;
                    }
                } else {
                    $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
                    $success = false;
                }
            }
        } else {
            $response_status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $success = false;
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

        if ($customer) {
            $customer_id = $customer['id'];
            $mobile = substr($customer['phone'], 1);
            $customer_metafields = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_balance_metafield_id = $customer_metafields->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_POINTS_KEY,
                ShopifyConstants::METAFIELD_INDEX_ID,
            );
            $customer_member_id = $customer_metafields->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_ID_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            );

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
                        $order_metafields = ShopifyAdmin::fetchMetafield($order_id, ShopifyConstants::ORDER_RESOURCE);
                        $order_transaction_lists = $order_metafields->metafield(
                            ZAPConstants::TRANSACTION_NAMESPACE,
                            ZAPConstants::TRANSACTION_LIST_KEY
                        );
                        $transactions = collect($order_transaction_lists
                            ? json_decode($order_transaction_lists[ShopifyConstants::METAFIELD_INDEX_VALUE], true)
                            : []);
                        $transactions->push([
                            ZAPConstants::TRANSACTION_REFERENCE_KEY => $zap_transaction_reference_no,
                            ZAPConstants::TRANSACTION_POINTS_KEY => $total_points,
                            ZAPConstants::TRANSACTION_STATUS_KEY => ZAPConstants::TRANSACTION_STATUS_CLEARED,
                            'fulfilled_at' => Carbon::now()->toIso8601String(),
                        ]);

                        // Update or add the point balance in the customer metafield
                        $new_customer_balance = $customer_balance_metafield_id
                            ? ShopifyAdmin::updateMetafieldById($customer_balance_metafield_id, $current_customer_balance)
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
                            // log warning to slack to manual update the metafield, still return true
                        }

                        if ($order_transaction_lists) {
                            $last_transaction_reference_metafield_id = $order_metafields->metafield(
                                ZAPConstants::TRANSACTION_NAMESPACE,
                                ZAPConstants::LAST_TRANSACTION_KEY,
                                ShopifyConstants::METAFIELD_INDEX_ID
                            );

                            if (ShopifyAdmin::updateMetafieldById(
                                $order_transaction_lists[ShopifyConstants::METAFIELD_INDEX_ID],
                                $transactions->toJson(),
                            )->failed()) {
                                // log warning to slack to manual update the metafield, still return true
                            }
                            if (ShopifyAdmin::updateMetafieldById(
                                $last_transaction_reference_metafield_id,
                                $zap_transaction_reference_no,
                            )->failed()) {
                                // log warning to slack to manual update the metafield, still return true
                            }
                        } else {
                            if (ShopifyAdmin::addMetafields(
                                ShopifyConstants::ORDER_RESOURCE,
                                $order_id,
                                collect()
                                    ->push([
                                        'key' => ZAPConstants::TRANSACTION_LIST_KEY,
                                        'value' => $transactions->toJson(),
                                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                    ])
                                    ->push([
                                        'key' => ZAPConstants::LAST_TRANSACTION_KEY,
                                        'value' => $zap_transaction_reference_no,
                                        'namespace' => ZAPConstants::TRANSACTION_NAMESPACE
                                    ])
                                )->failed()
                            ) {
                                // log warning to slack to manual add the metafield, still return true
                            }
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

                }

            } else {
                // Log warning here, but return a success response
            }
        }

        return response()->json(['success' => $success], $status);
    }
}
