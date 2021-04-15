<?php

namespace App\ZAP;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ZAP extends ZAPApiHandler
{
    private $http_get;
    private $http_post;
    private $bearer_token;

    public $api_endpoint;
    public $api_access_token;
    public $api_version;
    public $merchant_id;
    public $branch_id;

    public function __construct()
    {
        $this->api_access_token = config('app.zap_access_token');
        $this->api_version = config('app.zap_api_version');
        $this->api_endpoint = config('app.zap_api_endpoint');
        $this->merchant_id = config('app.zap_merchant_id');
        $this->branch_id = config('app.zap_branch_id');

        $this->bearer_token = 'Bearer ' . $this->getAccessToken();

        throw_if(empty($this->api_version), 'RuntimeException', 'zap api version is unknown');
        throw_if(empty($this->api_endpoint), 'RuntimeException', 'zap api endpoint is not set');
        throw_if(empty($this->api_access_token), 'RuntimeException', 'zap api access token is not set');

        $default_headers = Http::withHeaders([
            'Authorization' => $this->bearer_token,
        ]);

        $this->http_post = $default_headers;
        $this->http_get = $default_headers;
    }

    public function getAccessToken(): string
    {
        return config('app.zap_access_token');
    }

    public function createMember(
        string $mobile_number,
        string $first_name,
        string $last_name,
        string $email,
        string $gender,
        Carbon $birthday,
        bool $is_verified_email = true
    ): Collection {
        $url = $this->api_endpoint . '/' . $this->api_version . '/register';
        return $this->http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'branchId' => $this->branch_id,
            'firstName' => $first_name,
            'lastName' => $last_name,
            'email' => $email,
            'gender' => $gender,
            'birthday' => $birthday->format('Y-m-d'),
            'isVerifiedEmail' => $is_verified_email,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function sendOTP(string $purpose, string $mobile_number, string $merchant_id): Collection
    {
        $url = $this->api_endpoint . '/' . $this->api_version . '/otp/send/' . $purpose;
        return $this->http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function verifyOTP(string $reference_id, string $otp, string $merchant_id): Collection
    {
        $url = $this->api_endpoint . '/' . $this->api_version . '/otp/verify';
        return $this->http_post->post($url, [
            'refId' => $reference_id,
            'otp' => $otp,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function resendOTP(string $reference_id, string $merchant_id): Collection
    {
        $url = $this->api_endpoint . '/' . $this->api_version . '/otp/resend';
        return $this->http_post->post($url, [
            'refId' => $reference_id,
            'merchantId' => $merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function earnPoints(
        float $transaction_amount,
        string $mobile_number,
        string $tag_uuid,
        string $branch_id
    ): Collection {
        $url = $this->api_endpoint . '/' . $this->api_version . '/transaction/earn';
        return $this->http_post->post($url, [
            'transactionAmount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
            'branchId' => $branch_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function redeemPoints(
        float $transaction_amount,
        string $mobile_number,
        string $tag_uuid
    ): Collection {
        $url = $this->api_endpoint . '/' . $this->api_version . '/transaction/redeem';
        return $this->http_post->post($url, [
            'transactionAmount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function getUserTransactions(string $mobile_number, string $branch_id): Collection
    {
        $url = $this->api_endpoint . '/' . $this->api_version . '/user/transactions';
        return $this->http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'branchId' => $branch_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
        ->collect();
    }

    public function getMembershipData(string $mobile_number)
    {
        $url = $this->api_endpoint . '/' . $this->api_version . '/membership';
        return $this->http_post->post($url, [
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
        ])
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
       ->collect();
    }
}