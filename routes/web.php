<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Revolution\Google\Sheets\Sheets;

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

Route::get('signup', [CustomerController::class, 'registerForm'])->name('signup');
Route::post('signup', [CustomerController::class, 'postProcessRegistration'])->name('register');
Route::post('verify-otp', [CustomerController::class, 'verifyOTP']);

Route::prefix('member')->group(function () {
    Route::get('data', [CustomerController::class, 'getZAPMemberData'])->name('zap-member-data');
    Route::get('transactions', [CustomerController::class, 'getZAPMemberTransactions'])
        ->name('zap-member-transactions');
});

// Route::prefix('shopify')->middleware('shopify-verify-webhook')->group(function () {

Route::prefix('shopify')->group(function () {
    Route::post('fulfill', [WebhookController::class, 'onOrderFulfilled']);
    Route::post('fulfillment-update', [WebhookController::class, 'onFulfillmentUpdate']);
});

Route::middleware('cors')->post('discount-code', [DiscountController::class, 'generateDiscountCode']);


Route::get('test', function () {
    $token = [
        'access_token'  => $user->access_token,
        'refresh_token' => $user->refresh_token,
        'expires_in'    => $user->expires_in,
        'created'       => $user->updated_at->getTimestamp(),
  ];

  // all() returns array
  $values = Sheets::setAccessToken($token)->spreadsheet('spreadsheetId')->sheet('Sheet 1')->all();

});
// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['auth'])->name('dashboard');

//require __DIR__.'/auth.php';
