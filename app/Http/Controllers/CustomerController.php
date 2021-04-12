<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
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

    public function processRegistration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'password' => 'required|confirmed',
            'confirm_password' => 'required',
        ]);

        if ($validator->fails()) {
            $this->errors = (new ViewErrorBag())->put('default', $validator->getMessageBag());
            $this->inputs = $request->all();
            return $this->register();
            // return Redirect::back()->withErrors($validator)->withInput();
        }
    }
}
