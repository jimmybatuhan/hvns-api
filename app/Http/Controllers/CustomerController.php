<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function registerForm(): View
    {
        Log::info('test');

        return view('register');
    }

    public function postProcessRegistration(Request $request)
    {
        $shopify_customer_id = null;
        $zap_member_id = null;
        $view = Redirect::to(config('app.shopify_store_url') . '/account/login');
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|bail',
            'last_name' => 'required|bail',
            'email' => 'required|email|bail',
            'gender' => 'required|in:Male,Female|bail',
            'birthday' => 'required|date|bail',
            'mobile' => 'required|bail',
            'password' => 'required|min:8|confirmed|bail',
            'password_confirmation' => 'required|bail',
        ]);

        $validator->after(function ($validator) use ($request, &$shopify_customer_id, &$zap_member_id) {
            // Attempt to create a shopify customer
            $shopify_response = ShopifyAdmin::createCustomer(
                $request->first_name,
                $request->last_name,
                $request->email,
                $request->mobile,
                $request->password
            );

            $shopify_response_body = $shopify_response->collect();

            if (! $shopify_response->failed()) {
                $shopify_customer_id = $shopify_response_body["customer"]["id"];

                // Attempt to create a member in ZAP
                $zap_response = ZAP::createMember(
                    $request->mobile,
                    $request->first_name,
                    $request->last_name,
                    $request->email,
                    $request->gender,
                    new Carbon($request->birthday)
                );

                $zap_response_body = $zap_response->collect();

                if ($zap_response->failed()) {
                    // Delete the created customer in shopify since ZAP fails to register the customer
                    ShopifyAdmin::deleteCustomer($shopify_customer_id);

                    switch ($zap_response_body["errorCode"]) {
                        case ZAPConstants::EMAIL_ALREADY_EXISTS:
                            $validator->errors()->add('email', 'email already exists.');
                            break;
                        case ZAPConstants::MOBILE_ALREADY_EXISTS:
                            $validator->errors()->add('mobile', 'mobile no. already exists.');
                            break;
                    }
                } else {
                    $zap_member_id = $zap_response_body['data']['userId'];
                }
            } else {
                collect($shopify_response_body["errors"])
                    ->each(function ($item, $key) use (&$validator) {
                        // mobile in shopify is named phone.
                        $field = $key === 'phone' ? 'mobile' : $key;
                        $first_error = $item[0];
                        $validator->errors()->add($field, $field . ' ' . $first_error);
                    });
            }
        });

        if (! $validator->fails()) {
            /**
             * Attach the member id  to the shopify customer resource
             */
            ShopifyAdmin::addMetafieldsToResource(
                ShopifyConstants::CUSTOMER_RESOURCE,
                $shopify_customer_id,
                collect()->push([
                    'key' => ZAPConstants::MEMBER_ID_KEY,
                    'namespace' => ZAPConstants::MEMBER_NAMESPACE,
                    'value' => $zap_member_id,
                ])
            );
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

        return $view;
    }

    public function updateForm(Request $request): View
    {

        $viewData = [];

        $validator = Validator::make($request->all(), [
            'shopify_customer_id' => 'required|bail',
        ]);

        if (! $validator->fails()) {

            $customerData = $this->getCustomerData($request);
            return view('update', $customerData);

        }else{
            abort(404);
        }
    }

    private function getCustomerData(Request $request): Array
    {
        $customerData = [];

        $shopifyCustomerResp = ShopifyAdmin::getCustomer($request->shopify_customer_id);

        if(!$shopifyCustomerResp->serverError()){
            if ($shopifyCustomerResp->status() === Response::HTTP_NOT_FOUND) {
                $customerData = [
                    'success' => false,
                    'message' => 'Shopify Customer does not exist'
                ];
            }else{

                $shopifyCustomerData = $shopifyCustomerResp->collect();
                $zapMembershipResp = ZAP::getMembershipData(substr($shopifyCustomerData['customer']['phone'], 1));

                if (!$zapMembershipResp->failed()) {
                    $zapMembershipData = $zapMembershipResp->collect();

                    $customerData = [
                        'success' => true,
                        'customer' => [
                            'shopify_customer_id' => $request->shopify_customer_id,
                            'first_name' => $shopifyCustomerData['customer']['first_name'],
                            'last_name' => $shopifyCustomerData['customer']['last_name'],
                            'email' => $shopifyCustomerData['customer']['email'],
                            'phone' => $shopifyCustomerData['customer']['phone'],
                            'birthday' => $zapMembershipData['data']['birthday'],
                            'gender' => ucwords($zapMembershipData['data']['gender']),
                        ]
                    ];
                }else{
                    $customerData = [
                        'success' => false,
                        'message' => 'Zap Customer does not exist'
                    ];
                }
            }
        }else{
            $customerData = [
                'success' => false,
                'message' => 'Shopify Customer does not exist'
            ];
        }

        return $customerData;
    }

    public function postProcessUpdate(Request $request)
    {
        $shopify_customer_id = null;
        $zap_member_id = null;

        //TODO update to the correct url
        $view = Redirect::to(config('app.shopify_store_url') . '/account/update');

        $validator = Validator::make($request->all(), [
            'shopify_customer_id' => 'required|bail',
            'first_name' => 'required|bail',
            'last_name' => 'required|bail',
            'email' => 'required|email|bail',
            'gender' => 'required|in:Male,Female|bail',
            'birthday' => 'required|date|bail',
            'mobile' => 'required|bail',
        ]);

        $oldCustomerData = $this->getCustomerData($request);

        $validator->after(function ($validator) use ($request, $oldCustomerData) {
            if($oldCustomerData['success']){
                $shopify_response = ShopifyAdmin::updateCustomer(
                    $request->shopify_customer_id,
                    $request->first_name,
                    $request->last_name,
                    $request->email,
                    $request->mobile
                );

                if (!$shopify_response->failed()) {
                    //TODO Add Shopify Update once OTP Process is confirmed

                    // $zap_response = ZAP::updateMember(
                    //     $request->mobile
                    //     $request->first_name,
                    //     $request->last_name,
                    //     $request->email,
                    //     $request->gender,
                    //     $request->birthday,
                    // );

                    // if ($zap_response->failed()) {
                    //     $validator->errors()->add('shopify_customer_id', 'Zap Customer failed to update');
                    // }

                } else {
                    $validator->errors()->add('shopify_customer_id', 'Shopify Customer failed to update');
                }

            }else{
                $validator->errors()->add('shopify_customer_id', 'Shopify Customer does not exist');
            }
        });

        if ($validator->fails()) {
            $view = view('update', [
                'customer' => $oldCustomerData,
                'errors' => (new ViewErrorBag())->put('default', $validator->getMessageBag()),
                'inputs' => $request->all(),
            ]);
        }

        return $view;
    }

    public function verifyOTP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => [
                'required',
                function ($attribute, $value, $fail) {
                    // verify otp in zap
                },
            ],
        ]);

        if (! $validator->fails()) {
            // Create Shopify account
        } else {
            $view = view('otp', [
                'errors' => (new ViewErrorBag())->put('default', $validator->getMessageBag()),
            ]);
        }

        return $view;
    }

    public function getZAPMemberTransactions(): View
    {
        return view('member-transactions');
    }

    public function getZAPMemberData(Request $request): View
    {
        // $member = ZAP::getMembershipData($request->mobile_number);
        return view('member-data');
    }
}
