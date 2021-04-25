<?php

namespace App\Http\Controllers;

use App\Shopify\Constants as ShopifyConstants;
use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Constants as ZAPConstants;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
                ->push([
                    'key' => ZAPConstants::MEMBER_BIRTHDAY_KEY,
                    'namespace' => ZAPConstants::MEMBER_NAMESPACE,
                    'value' => new Carbon($request->birthday),
                ])
                ->push([
                    'key' => ZAPConstants::MEMBER_GENDER_KEY,
                    'namespace' => ZAPConstants::MEMBER_NAMESPACE,
                    'value' => $request->gender,
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

    public function postProcessUpdate(Request $request): JSONResponse
    {
        $response = [];
        $customer_data = [];

        $validator = Validator::make($request->all(), [
            'shopify_customer_id' => ['required', 'bail', function( $attribute, $value, $fail ) use( &$customer_data ){
                $customer_data = $this->getCustomerData( $value );
                if(! $customer_data['success']){
                    $fail( $customer_data['error'] );
                }
            }],
            'first_name' => 'required|bail',
            'last_name' => 'required|bail',
            'email' => 'required|email|bail',
            'gender' => 'required|in:Male,Female|bail',
            'gender_metafield_id' => 'required|bail',
            'birthday' => 'required|date|bail',
            'birthday_metafield_id' => 'required|bail',
            'mobile' => 'required|bail',
            'otp_ref' => 'required|bail',
            'otp_code' => 'required|bail',
        ]);

        if (! $validator->fails()) {

            $shopify_response = ShopifyAdmin::updateCustomer(
                $request->shopify_customer_id,
                $request->first_name,
                $request->last_name,
                $request->email,
                $request->mobile
            );

            if (!$shopify_response->failed()) {

                ShopifyAdmin::updateMetafieldById(
                    $request->birthday_metafield_id,
                    $request->birthday
                );

                ShopifyAdmin::updateMetafieldById(
                    $request->gender_metafield_id,
                    $request->gender
                );

                $zap_response = ZAP::updateMember(
                    substr( $request->mobile, 1 ),
                    $request->first_name,
                    $request->last_name,
                    $customer_data['customer']['email'] !== $request->email ? $request->email : '',
                    $request->gender,
                    new Carbon($request->birthday),
                    $request->otp_ref,
                    $request->otp_code,
                );

                $zap_data = $zap_response->collect();

                if ($zap_response->failed()) {
                    if($zap_data['error'] == 'Unauthorized'){
                        $response = [
                            'success' => false,
                            'errors' => [
                                'otp_code' => [
                                    'OTP Code Incorrect'
                                ]
                            ]
                        ];
                    }else{
                        $response = [
                            'success' => false,
                            'errors' => [
                                'message' => [
                                    'ZAP Customer failed to update'
                                ]
                            ]
                        ];
                    }
                }else{
                    $response = [
                        'success' => true,
                        'message' => 'Customer Updated'
                    ];
                }

            } else {
                $response = [
                    'success' => false,
                    'errors' => [
                        'message' => [
                            'Shopify Customer failed to update'
                        ]
                    ]
                ];
            }

        }else{
            $response = [
                'success' => false,
                'errors' => $validator->errors(),
            ];
        }

        return response()->json($response);
    }

    private function getCustomerData(String $shopify_customer_id): array
    {

        $customer_data_resp = [];
        $shopify_customer_resp = ShopifyAdmin::getCustomer( $shopify_customer_id );

        if(! $shopify_customer_resp->serverError()){
            if($shopify_customer_resp->status() === Response::HTTP_NOT_FOUND) {
                $customer_data_resp = [
                    'success' => false,
                    'error' => 'Shopify user does not exist'
                ];
            }else{
                $shopify_customer_data = $shopify_customer_resp->collect();
                $zap_membership_resp = ZAP::getMembershipData( substr( $shopify_customer_data['customer']['phone'], 1 ) );

                if(! $zap_membership_resp->failed()) {
                    $customer_data_resp = [
                        'success' => true,
                        'customer' => [
                            'email' => $shopify_customer_data['customer']['email']
                        ]
                    ];
                }else{
                    $customer_data_resp = [
                        'success' => false,
                        'error' => 'Zap Customer does not exist'
                    ];
                }

            }
        }else{
            $customer_data_resp = [
                'success' => false,
                'error' => 'Shopify user does not exist'
            ];
        }

        return $customer_data_resp;
    }

    public function requestUpdateOTP(Request $request): JSONResponse
    {
        $resp = [];

        $validator = Validator::make($request->all(), [
            'mobile' => 'required',
        ]);

        if (! $validator->fails()) {
            $zap_resp = ZAP::sendOTP(
                ZAPConstants::OTP_PURPOSE_MEMBERSHIP_UPDATE,
                substr($request->mobile, 1)
            );
            $otp_data = $zap_resp->collect();
            $resp = [
                'success' => true,
                'otp_ref_id' => $otp_data['data']['refId'],
            ];

        } else {
            $resp = [
                'success' => false,
                'errors' => $validator->getMessageBag(),
            ];
        }

        return response()->json($resp);
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
