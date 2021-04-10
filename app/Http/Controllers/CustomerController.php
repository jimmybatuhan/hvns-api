<?php

namespace App\Http\Controllers;

use App\Shopify\Facades\ShopifyAdmin;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function register(): View
    {
        return view('register');
    }

    public function processRegistration(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'password' => 'required|confirmed',
            'confirm_password' => 'required',
        ]);
    }
}
