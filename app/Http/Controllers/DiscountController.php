<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Facades\ZAP;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DiscountController extends Controller
{
    public function generateDiscountCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|bail',
            'shopify_customer_id' => 'required|bail',
        ]);

        $discountCode = "";

        if (! $validator->fails()) {

            //generate discount code name
            $shopifyCustomerID = $request->shopify_customer_id;

            $discountCode = $this->generateNameForDiscountCode($shopifyCustomerID);
            $discountCodeName = "ZAP_POINTS_" . $discountCode;

            //Get Zap Balance
            $customerBalanceData = ZAP::inquireBalance($request->mobile);

            //TODO: Change this to for loop to get the correct currency if they have multiples
            $customerDiscountPoints = strval(($customerBalanceData['data']['currencies'][0]['validPoints']) * -1);
            $existingDiscountCodeResp = ShopifyAdmin::getDiscountCode($discountCode);

            //Check if Discount Already Exists
            if($existingDiscountCodeResp->getStatusCode() == 200){

                //If exists check if price rules is updated with the new ZAP Balance
                $existingDiscountCodeData = json_decode($existingDiscountCodeResp->getBody(), true);
                $discountPriceRuleID = $existingDiscountCodeData['discount_code']['price_rule_id'];

                $existingPriceRuleResp = ShopifyAdmin::getPriceRule($discountPriceRuleID);
                $existingPriceRuleData = json_decode($existingPriceRuleResp->getBody(), true);
                $existingPriceRuleAmount = $existingPriceRuleData['price_rule']['value'];

                if($existingPriceRuleAmount != $customerDiscountPoints){
                    //If price rule is not update, update price rule
                    $resp = ShopifyAdmin::updatePriceRuleAmount(
                        $discountPriceRuleID,
                        $customerDiscountPoints
                    );
                }

            }else{
                //Create Price Rule
                $newPriceRuleData = json_decode(ShopifyAdmin::createPriceRule(
                    $discountCodeName,
                    $shopifyCustomerID,
                    $customerDiscountPoints
                )->getBody(), true);

                //Create Discount
                $newDiscountCodeData = json_decode(ShopifyAdmin::createDiscountCode(
                    $newPriceRuleData['price_rule']['id'],
                    $discountCode
                )->getBody());
            }

            //Return Discount Code
            return response()->json([
                "discount_code" => $discountCode,
            ]);
        } else {
            /**
             * Re render the register from with the errors, have to put the errors manually due to
             * no session exists in the iframe.
             */
            $view = view('register', [
                'errors' => (new ViewErrorBag())->put('default', $validator->getMessageBag()),
                'inputs' => $request->all(),
            ]);
        }
    }

    public function generateNameForDiscountCode(string $customer_id): String
    {
        //change if they want a different naming convention for the disount code
        return $customer_id;
    }

    public function getCustomerExistingDiscountCode(){

    }
}
