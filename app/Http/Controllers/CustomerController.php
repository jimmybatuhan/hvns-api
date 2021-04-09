<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function register(): View
    {
        return view('register');
    }

    public function processRegistration()
    {

    }
}
