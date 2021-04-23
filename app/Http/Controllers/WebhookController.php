<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ShopPromo\Facades\ShopPromo;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $customer = $body['customer'];
        $line_items = collect($body['line_items']);

        if ($customer) {
            // remove special characters (ex. (+00)000-000-0000 -> 0000000000)
            $mobile = preg_replace('/([^\d])/', '', $customer['phone']);
            $customer_id = $customer['id'];
            $shopify_response = ShopifyAdmin::fetchMetafield($customer_id, ShopifyConstants::CUSTOMER_RESOURCE);
            $customer_member_id = $shopify_response->metafield(
                ZAPConstants::MEMBER_NAMESPACE,
                ZAPConstants::MEMBER_ID_KEY,
                ShopifyConstants::METAFIELD_INDEX_VALUE
            );

            // if customer has a member id from ZAP, this means we should add a point in its account
            if ($customer_member_id) {
                $metafields = collect([
                    'order_id' => $body['id'],
                    'sub_total' => (float) $body['current_subtotal_price'],
                ]);
                $total_points = $line_items
                    ->map(fn (array $item) => ShopPromo::calculatePoints(
                        $item['sku'],
                        $item['quantity'],
                        floatval($item['price']
                    )))
                    ->sum();
                $zap_response = ZAP::addPoints($total_points, $mobile, $metafields->toJson());

                if ($zap_response->ok()) {
                    $zap_response_body = $zap_response->collect();
                    $shopify_response = ShopifyAdmin::addMetafieldsToResource(
                        ShopifyConstants::ORDER_RESOURCE,
                        $metafields['order_id'],
                        collect()
                            ->push([
                                'key' => ZAPConstants::TRANSACTION_REFERENCE_KEY,
                                'value' =>$zap_response_body['data']['refNo'],
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE,
                            ])
                            ->push([
                                'key' => ZAPConstants::TRANSACTION_STATUS_KEY,
                                'value' => ZAPConstants::TRANSACTION_STATUS_CLEARED,
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE,
                            ])
                            ->push([
                                'key' => ZAPConstants::TRANSACTION_POINTS_KEY,
                                'value' => $total_points,
                                'namespace' => ZAPConstants::TRANSACTION_NAMESPACE,
                            ])
                    );

                    if ($shopify_response->failed()) {
                        // Log error here
                        $success = false;
                        $status = Response::HTTP_INTERNAL_SERVER_ERROR;
                    }
                } else {
                    // Log error here
                    $success = false;
                    $status = Response::HTTP_INTERNAL_SERVER_ERROR;
                }
            } else {
                // Log warning here, but return a success response
            }
        }

        return response()->json(['success' => $success], $status);
    }
}
