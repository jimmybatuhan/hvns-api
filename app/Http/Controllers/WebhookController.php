<?php

namespace App\Http\Controllers;

use App\ZAP\Facades\ZAP;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function onOrderFulfilled(Request $request): void
    {
        // TODO validate duplicate orders
        $body = $request->all();
        $customer = $body['customer'];
        $sub_total = $body['current_subtotal_price'];

        // remove special characters (ex. (+00)000-000-0000 -> 0000000000)
        $mobile = preg_replace('/([^\d])/', '', $customer['phone']);
        $metafields = collect([
            'orderId' => $body['id'],
            'sub_total' => $body['current_subtotal_price'],
        ])->toJson();

        $total_points = number_format($sub_total * ZAP::getRewardPercentage(), 2);

        ZAP::addPoints($total_points, $mobile, $metafields);
    }
}
