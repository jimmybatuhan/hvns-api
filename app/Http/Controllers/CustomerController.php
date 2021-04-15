<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use App\ZAP\Facades\ZAP;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function registerForm(): View
    {
        return view('register');
    }

    public function postProcessRegistration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|bail',
            'last_name' => 'required|bail',
            'email' => 'required|email|bail',
            'gender' => 'required|in:Male,Female|bail',
            'birthday' => 'required|date|bail',
            'mobile' => [
                'required',
                'bail',
                function ($attribute, $value, $fail) {
                    $duplicate_mobile = ZAP::getMembershipData($value);
                    if ($duplicate_mobile['success']){
                        $fail('mobile no. already exists');
                    }
                },
            ],
            'password' => 'required|min:8|confirmed|bail',
            'password_confirmation' => 'required|bail',
        ]);

        $validator->after(function ($validator) use ($request) {
            $duplicate_customer = ShopifyAdmin::findCustomer([
                'email' => $request->email,
                'phone' => $request->mobile,
            ]);
            if (count($duplicate_customer['customers'])) {
                $validator->errors()->add('email', 'email already exists.');
            }
        });

        if (! $validator->fails()) {
            ZAP::createMember(
                $request->mobile,
                $request->first_name,
                $request->last_name,
                $request->email,
                $request->gender,
                new Carbon($request->birthday)
            );
            ShopifyAdmin::createCustomer(
                $request->first_name,
                $request->last_name,
                $request->email,
                $request->mobile,
                $request->password
            );
            $view = Redirect::to(config('app.shopify_store_url') . '/account/login');
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
