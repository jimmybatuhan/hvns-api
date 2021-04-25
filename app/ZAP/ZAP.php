<?php

namespace App\ZAP;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ZAP extends ZAPApiHandler
{
    private $http;
    private $bearer_token;

    public $api_endpoint;
    public $api_access_token;
    public $api_version;
    public $merchant_id;
    public $branch_id;
    public $location_id;

    private const ZAP_REWARD_PECENTAGE = 0.02; // 2%

    public function __construct()
    {
        $this->api_access_token = config('app.zap_access_token');
        $this->api_version = config('app.zap_api_version');
        $this->api_endpoint = config('app.zap_api_endpoint');
        $this->merchant_id = config('app.zap_merchant_id');
        $this->branch_id = config('app.zap_branch_id');
        $this->location_id = config('app.zap_location_id');

        $this->bearer_token = 'Bearer ' . $this->api_access_token;

        throw_if(empty($this->api_version), 'RuntimeException', 'zap api version is unknown');
        throw_if(empty($this->api_endpoint), 'RuntimeException', 'zap api endpoint is not set');
        throw_if(empty($this->api_access_token), 'RuntimeException', 'zap api access token is not set');

        $this->api_url = $this->api_endpoint . '/' . $this->api_version;
        $this->http = Http::withHeaders([
            'Authorization' => $this->bearer_token,
        ]);
    }

    public function calculatePoints(float $amount): float
    {
        return (float) number_format($amount * self::ZAP_REWARD_PECENTAGE, 2);
    }

    public function createMember(
        string $mobile_number,
        string $first_name,
        string $last_name,
        string $email,
        string $gender,
        Carbon $birthday,
        bool $is_verified_email = true
    ): Response {
        return $this->http->post($this->api_url . '/register', [
            'birthday' => $birthday->format('Y-m-d'),
            'branchId' => $this->branch_id,
            'email' => $email,
            'firstName' => $first_name,
            'gender' => $gender,
            'isVerifiedEmail' => $is_verified_email,
            'lastName' => $last_name,
            'locationId' => $this->location_id,
            'mobileNumber' => $mobile_number,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function sendOTP(string $purpose, string $mobile_number): Response
    {
        return $this->http->post($this->api_url . '/otp/send/' . $purpose, [
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function verifyOTP(string $reference_id, string $otp, string $merchant_id): Response
    {
        return $this->http->post($this->api_url . '/otp/verify', [
            'refId' => $reference_id,
            'otp' => $otp,
            'merchantId' => $merchant_id,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function resendOTP(string $reference_id, string $merchant_id): Response
    {
        return $this->http->post($this->api_url . '/otp/resend', [
            'refId' => $reference_id,
            'merchantId' => $merchant_id,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    /**
     *  Note: 'earn points' endpoint have a 48 hours clearng period, to achieve
     *  a real time updating of points will use 'add points' endpoint instead.
     */
    public function addPoints(
        float $transaction_amount,
        string $mobile_number,
        string $metafields = ''
    ): Response {
        return $this->http->post($this->api_url . '/transaction/add-points', [
            'amount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
            'comment' => $metafields
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function deductPoints(
        float $points,
        string $mobile_number,
        string $metafields = ''
    ): Response {
        return $this->http->post($this->api_url . '/transaction/deduct-points', [
            'amount' => $points,
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
            'comment' => $metafields
        ]);
    }

    public function redeemPoints(
        float $transaction_amount,
        string $mobile_number,
        string $tag_uuid
    ): Response {
        return $this->http->post($this->api_url . '/transaction/redeem', [
            'transactionAmount' => $transaction_amount,
            'mobileNumber' => $mobile_number,
            'tagUuid' => $tag_uuid,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function voidTransaction(string $reference_no): Response
    {
        return $this->http->post($this->api_url . '/transaction/void', [
            'refNo' => $reference_no,
        ]);
    }

    public function getUserTransactions(string $mobile_number, string $branch_id): Response
    {
        return $this->http->post($this->api_url . '/user/transactions', [
            'mobileNumber' => $mobile_number,
            'branchId' => $branch_id,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function getMembershipData(string $mobile_number): Response
    {
        return $this->http->post($this->api_url . '/membership', [
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }

    public function inquireBalance(string $mobile_number): Response
    {
        return $this->http->post($this->api_url . '/user/balance/inquiry', [
            'mobileNumber' => $mobile_number,
            'branchId' => $this->branch_id,
        ]);
    }

    public function updateMember(
        string $mobile_number,
        string $first_name,
        string $last_name,
        string $email,
        string $gender,
        Carbon $birthday,
        string $otp_ref,
        string $otp_code
    ): Response {

        $membership_data = [
            'firstName' => $first_name,
            'lastName' => $last_name,
            'birthday' => $birthday->format('Y-m-d'),
            'gender' => $gender,
        ];

        if ($email !== '') {
            $membership_data['email'] = $email;
        }

        return $this->http->post($this->api_url . '/membership/update', [
            'mobileNumber' => $mobile_number,
            'merchantId' => $this->merchant_id,
            'branchId' => $this->branch_id,
            "otp" => [
                "refId" => $otp_ref,
                "code" => $otp_code
            ],
            'membership' => $membership_data
        ]);
        // TODO handle error response later on, focusing on the happy path first.
        // ->throw(fn ($response, $e) => self::handleHttpError($response, $e))
    }
}
