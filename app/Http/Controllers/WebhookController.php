<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function onFulfillmentUpdate(Request $request): JsonResponse
    {
        $body = $request->all();
        $order_id = $body['order_id'];
        $fulfillment_status = $body['status'];
        $shopify_response = ShopifyAdmin::getOrderById($order_id);
        $order_resource = $shopify_response->collect();
        $customer = $order_resource['order']['customer'];

        $shopify_response = ShopifyAdmin::retrieveMetafieldFromResource(
            $order_id,
            ShopifyConstants::ORDER_RESOURCE
        );

        $zap_transaction_metafields = $shopify_response->collect()['metafields'];
        $zap_transaction_status = Arr::first($zap_transaction_metafields, fn ($metafield) =>
            $metafield['namespace'] === ZAPConstants::TRANSACTION_NAMESPACE &&
            $metafield['key'] === ZAPConstants::TRANSACTION_STATUS_KEY
        );
        $calculated_points = Arr::first($zap_transaction_metafields, fn ($metafield) =>
            $metafield['namespace'] === ZAPConstants::TRANSACTION_NAMESPACE &&
            $metafield['key'] === ZAPConstants::TRANSACTION_POINTS_KEY
        );

        if ($fulfillment_status === ShopifyConstants::FULFILLMENT_CANCELLED) {
            ZAP::deductPoints(
                $calculated_points['value'],
                preg_replace('/([^\d])/', '', $customer['phone'])
            );
            ShopifyAdmin::updateMetafieldById(
                $zap_transaction_status['id'],
                ZAPConstants::TRANSACTION_STATUS_VOIDED
            );
        }

        return response()->json(['success' => true], Response::HTTP_OK);
    }

    public function onOrderFulfilled(Request $request): JsonResponse
    {
        // TODO validate duplicate orders
        $body = $request->all();
        $customer = $body['customer'];
        $sub_total = (float) $body['current_subtotal_price'];
        $metafields = collect([
            'orderId' => $body['id'],
            'sub_total' => $sub_total,
        ]);

        // remove special characters (ex. (+00)000-000-0000 -> 0000000000)
        $mobile = preg_replace('/([^\d])/', '', $customer['phone']);

        // JODO validate if the mobile number is a ZAP member
        // TODO handle ZAP response
        $calculated_points = ZAP::calculatePoints($sub_total);
        $zap_response = ZAP::addPoints($calculated_points, $mobile, $metafields->toJson());
        $zap_response_body = $zap_response->collect();

        // TODO handle Shopify response
        // attach the ZAP refId to the order resource
        ShopifyAdmin::addMetafieldsToResource(
            ShopifyConstants::ORDER_RESOURCE,
            $metafields['orderId'],
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
                    'value' => $calculated_points,
                    'namespace' => ZAPConstants::TRANSACTION_NAMESPACE,
                ])
        );

        return response()->json(['success' => true], Response::HTTP_OK);
    }
}
