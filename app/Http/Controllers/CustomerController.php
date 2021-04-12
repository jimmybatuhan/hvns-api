<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class CustomerController extends Controller
{
    private $errors = [];
    private $inputs = [];

    public function register(): View
    {
        $view_data = [];

        if ($this->errors && $this->inputs) {
            $view_data['errors'] = $this->errors;
            $view_data['input'] = $this->inputs;
        }

        return view('register', $view_data);
    }

    public function postProcessRegistration(Request $request)
    {
        $view = null;

        // TODO add validation rule where the system checks if the mobile number is already in ZAP, fail the validation if true.
        // TODO validate if the email already exists in shopify
        $validator = Validator::make($request->all(), [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'password' => 'required|confirmed',
            'confirm_password' => 'required',
        ]);

        /**
         * Re render the register from with the errors, have to put the errors manually due to
         * no session exists in the iframe.
         */
        if ($validator->fails()) {
            $this->errors = (new ViewErrorBag())->put('default', $validator->getMessageBag());
            $this->inputs = $request->all();
            $view = $this->register();
        }

        // TODO send and show OTP form if inputs passed the validations,
        return $view;
    }
}
