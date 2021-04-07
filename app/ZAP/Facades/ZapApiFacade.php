<?php

namespace App\ZAP\Facades;

use App\ZAP\ZAPApiHandler;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ZapApiFacade extends ZAPApiHandler
{
    private Http $http_get;
    private Http $http_post;
    private string $bearer_token;

    public string $api_endpoint;
    public string $api_access_token;
    public string $api_version;

    public function __construct()
    {
        $this->api_access_token = config('app.zap_access_token');
        $this->api_version = config('app.zap_api_version');
        $this->api_endpoint = config('app.zap_api_endpoint');

        $this->bearer_token = 'Bearer ' . self::getAccessToken();

        throw_if(empty($this->api_version), 'RuntimeException', 'zap api version is unknown');
        throw_if(empty($this->api_endpoint), 'RuntimeException', 'zap api endpoint is not set');
        throw_if(empty($this->api_access_token), 'RuntimeException', 'zap api access token is not set');

        $default_headers = Http::withHeaders([
            'Authentication' => $this->bearer_token,
        ]);

        $this->http_post = $default_headers;
        $this->http_get = $default_headers;
    }

    public static function getAccessToken(): string
    {
        return config('app.zap_access_token');
    }

    public static function register(
        string $mobile_number,
        string $branch_id,
        string $first_name,
        string $last_name,
        string $gender,
        Carbon $birthday,
        string $location_id,
        string $email,
        bool $is_verified_email = false
    ): Collection {
        $url = self::$api_endpoint . '/' . self::$api_version . '/register';
        return self::$http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'branchId' => $branch_id,
            'firstName' => $first_name,
            'lastName' => $last_name,
            'gender' => $gender,
            'birthday' => $birthday->format('yyyy-mm-dd'),
            'locationId' => $location_id,
            'email' => $email,
            'isVerifiedEmail' => $is_verified_email,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function sendOTP(string $purpose, string $mobile_number, string $merchant_id): Collection
    {
        $url = self::$api_endpoint . '/' . self::$api_version . '/otp/send/' . $purpose;
        return self::$http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function verifyOTP(string $reference_id, string $otp, string $merchant_id): Collection
    {
        $url = self::$api_endpoint . '/' . self::$api_version . '/otp/verify';
        return self::$http_post->post($url, [
            'refId' => $reference_id,
            'otp' => $otp,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function resendOTP(string $reference_id, string $merchant_id): Collection
    {
        $url = self::$api_endpoint . '/' . self::$api_version . '/otp/resend';
        return self::$http_post->post($url, [
            'refId' => $reference_id,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function earnPoints(
        float $transaction_amount,
        string $mobile_number,
        string $tag_uuid,
        string $branch_id
    ): Collection {
        $url = self::$api_endpoint . '/' . self::$api_version . '/transaction/earn';
        return self::$http_post->post($url, [
            'transactionAmount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
            'branchId' => $branch_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function redeemPoints(
        float $transaction_amount,
        string $mobile_number,
        string $tag_uuid
    ): Collection {
        $url = self::$api_endpoint . '/' . self::$api_version . '/transaction/redeem';
        return self::$http_post->post($url, [
            'transactionAmount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function getUserTransactions(string $mobile_number, string $branch_id): Collection
    {
        $url = self::$api_endpoint . '/' . self::$api_version . '/user/transactions';
        return self::$http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'branchId' => $branch_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public static function getUserData(string $mobile_number, string $tag_uuid, string $merchant_id): Collection
    {
        $url = self::$api_endpoint . '/' . self::$api_version . '/membership';
        return self::$http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }
}