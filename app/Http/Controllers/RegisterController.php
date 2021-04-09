<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function registrationForm(): View
    {
        return view('register');
    }

    public function registerCustomer()
    {

    }
}
