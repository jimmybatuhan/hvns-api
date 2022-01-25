<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    abort(404);
});

Route::middleware(['log-route', 'cors'])->group(function () {
    Route::post('verify-otp', [CustomerController::class, 'verifyOTP']);
    Route::post('signup', [CustomerController::class, 'postProcessRegistration'])->name('register');

    Route::prefix('member')->group(function () {
        Route::post('create', [CustomerController::class, 'createZAPMember'])->name('create-zap-member');
        Route::get('data', [CustomerController::class, 'getZAPMemberData'])->name('zap-member-data');
        Route::get('transactions', [CustomerController::class, 'getZAPMemberTransactions'])->name('zap-member-transactions');
        Route::post('update', [CustomerController::class, 'postProcessUpdate'])->name('customer-update');
        Route::post('request-update-otp', [CustomerController::class, 'requestUpdateOTP'])->name('request-update-otp');
    });
    
    Route::post('discount-code', [DiscountController::class, 'generateDiscountCode']);
    Route::post('discount-points', [DiscountController::class, 'getDiscountPoints']);
    Route::post('register-claim-500', [DiscountController::class, 'registerClaim500']);

    Route::get('test', [WebhookController::class, 'test']);
});

Route::prefix('shopify')->middleware(['shopify-verify-webhook', 'log-route'])->group(function () {
    Route::post('order-create', [WebhookController::class, 'onOrderCreate']);
    Route::post('order-canceled', [WebhookController::class, 'onOrderCanceled']);
    Route::post('order-update', [WebhookController::class, 'onOrderUpdate']);
    Route::post('order-fulfill', [WebhookController::class, 'onOrderFulfill']);
});

// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['auth'])->name('dashboard');

//require __DIR__.'/auth.php';
