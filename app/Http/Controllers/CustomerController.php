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
use Illuminate\Validation\Validator as ValidationValidator;
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
        $resp = [];

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

        if (! $validator->fails()) {

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

                    $resp = [
                        "success" => false,
                        "errors" => $validator->getMessageBag(),
                    ];

                } else {
                    $zap_member_id = $zap_response_body['data']['userId'];

                    /**
                     * Attach the member id  to the shopify customer resource
                     */
                    ShopifyAdmin::addMetafields(
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
                            'value' => (new Carbon($request->birthday))->format('Y-m-d'),
                        ])
                        ->push([
                            'key' => ZAPConstants::MEMBER_GENDER_KEY,
                            'namespace' => ZAPConstants::MEMBER_NAMESPACE,
                            'value' => $request->gender,
                        ])
                    );

                    $resp = [
                        "success" => true,
                        "message" => "user created",
                    ];
                }
            } else {
                collect($shopify_response_body["errors"])
                    ->each(function ($item, $key) use (&$validator) {
                        // mobile in shopify is named phone.
                        $field = $key === 'phone' ? 'mobile' : $key;
                        $first_error = $item[0];
                        $validator->errors()->add($field, $field . ' ' . $first_error);
                    });

                $resp = [
                    "success" => false,
                    "errors" => $validator->getMessageBag(),
                ];
            }

        } else {

            $resp = [
                "success" => false,
                "errors" => $validator->getMessageBag(),
            ];
        }

        return $resp;
    }

    public function postProcessUpdate(Request $request): JSONResponse
    {
        $response = [];
        $customer_data = [];

        $validator = Validator::make($request->all(), [
            'shopify_customer_id' => ['required', 'bail', function ($attribute, $value, $fail) use (&$customer_data) {
                $customer_data = $this->getCustomerData( $value );
                if (! $customer_data['success']) {
                    $fail($customer_data['error']);
                }
            }],
            'first_name' => 'required|bail',
            'last_name' => 'required|bail',
            'mobile' => 'required|bail',
            'otp_ref' => 'required|bail',
            'otp_code' => 'required|bail',
        ]);

        if (! $validator->fails()) {
            $shopify_response = ShopifyAdmin::updateCustomer(
                $request->shopify_customer_id,
                $request->first_name,
                $request->last_name
            );

            if (! $shopify_response->failed()) {
                $zap_response = ZAP::updateMember(
                    substr( $request->mobile, 1 ),
                    $request->first_name,
                    $request->last_name,
                    $request->otp_ref,
                    $request->otp_code
                );

                $zap_data = $zap_response->collect();

                if ($zap_response->failed()) {
                    if ($zap_data['error'] == 'Unauthorized') {
                        $response = [
                            'success' => false,
                            'errors' => [
                                'otp_code' => [
                                    'OTP Code Incorrect',
                                ],
                            ],
                        ];
                    } else {
                        $response = [
                            'success' => false,
                            'errors' => [
                                'message' => [
                                    'ZAP Customer failed to update',
                                ],
                            ],
                        ];
                    }
                } else {

                    $response = [
                        'success' => true,
                        'message' => 'Customer Updated',
                    ];
                }
            } else {
                $response = [
                    'success' => false,
                    'errors' => [
                        'message' => [
                            'Shopify Customer failed to update',
                        ],
                    ],
                ];
            }

        } else {
            $response = [
                'success' => false,
                'errors' => $validator->errors(),
            ];
        }

        return response()->json($response);
    }

    private function getCustomerData(string $shopify_customer_id): array
    {
        $customer_data_resp = [];
        $shopify_customer_resp = ShopifyAdmin::getCustomerById($shopify_customer_id);

        if (! $shopify_customer_resp->serverError()){
            if ($shopify_customer_resp->status() === Response::HTTP_NOT_FOUND) {
                $customer_data_resp = [
                    'success' => false,
                    'error' => 'Shopify user does not exist'
                ];
            } else {
                $shopify_customer_data = $shopify_customer_resp->collect();
                $zap_membership_resp = ZAP::getMembershipData(substr($shopify_customer_data['customer']['phone'], 1));

                switch ($zap_membership_resp->status()) {
                    case Response::HTTP_NOT_FOUND:
                        $customer_data_resp = [
                            'success' => false,
                            'error' => 'Zap Customer does not exist'
                        ];
                        break;
                    case Response::HTTP_UNAUTHORIZED:
                        $customer_data_resp = [
                            'success' => false,
                            'error' => 'Unauthorized'
                        ];
                        break;
                    case Response::HTTP_OK:
                        $customer_data_resp = [
                            'success' => true,
                            'customer' => [
                                'email' => $shopify_customer_data['customer']['email']
                            ]
                        ];
                        break;
                    default:
                        $customer_data_resp = [
                            'success' => false,
                            'error' => 'Unexpected Error'
                        ];
                }
            }
        } else {
            $customer_data_resp = [
                'success' => false,
                'error' => 'Unexpected Error'
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

    public function getZAPMemberTransactions(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'customer_id' => 'required',
            'mobile_no' => 'required',
            'start_date_filter' => 'date|nullable',
            'end_start_filter' => 'date|nullable',
        ]);

        $start_date = (new Carbon($request->start_date_filter))->format('Y-m-d');
        $end_date = (new Carbon($request->end_date_filter))->format('Y-m-d');

        $customer_id = $request->customer_id;
        $customer_mobile = $request->mobile_no;
        $zap_transactions = collect([]);
        $order_with_metafield = collect([]);
        $member_trasactions_response = ZAP::getUserTransactions($customer_mobile);
        $customer_order_response = ShopifyAdmin::getCustomerOrders($customer_id, $start_date, $end_date);

        if ($customer_order_response->ok()) {
            $shopify_orders = collect($customer_order_response->collect()['orders']);
            $order_with_metafield = $shopify_orders->map(function (array $order) {
                $order_metafields = ShopifyAdmin::fetchMetafield($order['id'], ShopifyConstants::ORDER_RESOURCE);
                $last_zap_transaction = $order_metafields->lastZAPTransaction() ?? [];
                $last_zap_status = $last_zap_transaction[ZAPConstants::TRANSACTION_STATUS_KEY] ?? null;
                $last_zap_point = $last_zap_transaction[ZAPConstants::TRANSACTION_POINTS_KEY] ?? 0.00;
                $earned = 0.00;
                $redeemed = 0.00;

                if ($last_zap_status === ZAPConstants::USE_POINT_STATUS) {
                    $redeemed = $last_zap_point;
                } else if ($last_zap_status === ZAPConstants::EARN_POINT_STATUS) {
                    $earned = $last_zap_point;
                }

                return [
                    'order_no' => $order['name'],
                    'transaction_date' => $order['created_at'],
                    'branch' => '',
                    'total' => $order['total_price'],
                    'points_earned' => $earned,
                    'points_redeemed' => $redeemed,
                    'point_status' => $last_zap_status,
                    'zap_transaction_number' => $last_zap_transaction[ZAPConstants::TRANSACTION_REFERENCE_KEY] ?? null
                ];
            });
        }

        if ($member_trasactions_response->ok()) {
            $transactions = collect($member_trasactions_response->collect()['data']['transactions']);
            $zap_transactions = $transactions->map(fn (array $transaction) => [
                'order_no' => $transaction['refNo'],
                'transaction_date' => $transaction['dateProcessed'],
                'branch' => $transaction['branchName'],
                'total' => $transaction['amount'],
                'points_earned' => $transaction['points'][0]['earned'],
                'points_redeemed' => $transaction['points'][0]['redeemed'],
                'point_status' => $transaction['status'],
                'zap_transaction_number' => $transaction['refNo']
            ]);

            if ($start_date && $end_date) {
                $zap_transactions = $zap_transactions
                    ->filter(fn ($transaction) =>
                        (new Carbon($transaction['transaction_date']))->between($start_date, $end_date)
                );
            }
        }

        $customer_transactions = $order_with_metafield
            ->merge($zap_transactions)
            ->sortBy(fn ($transaction) => $transaction['transaction_date'])
            ->toJSon();

        return response()->json($customer_transactions);
    }

    public function getZAPMemberData(Request $request): View
    {
        // $member = ZAP::getMembershipData($request->mobile_number);
        return view('member-data');
    }
}
