<?php

namespace App\Shopify;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyAdmin
{

    private $api_version;
    private $admin_url;
    private $access_token;

    private $customer_resource_url;
    private $discount_resource_url;
    private $price_rules_resource_url;

    private $http_post;

    public function __construct()
    {
        $this->api_version = config('app.shopify_api_version');
        $this->admin_url = config('app.shopify_store_admin_url');
        $this->access_token = config('app.shopify_access_token');

        $admin_api = $this->admin_url . '/admin/api/' . $this->api_version;

        $this->customer_resource_url = $admin_api . '/customers.json';
        $this->discount_resource_url = $admin_api . '/price_rules/{price_rule_id}/discount_codes.json';
        $this->price_rules_resource_url = $admin_api . '/price_rules.json';

        $this->http_post = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->access_token,
        ]);
    }

    public function createCustomer(
        string $first_name,
        string $last_name,
        string $email,
        string $phone,
        string $password
    ): Collection {
        return $this->http_post->post($this->customer_resource_url, [
            'customer' => [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'password_confirmation' => $password,
                'verified_email' => false,
            ],
        ])
        ->collect();
    }

    public function createPriceRule(string $title, string $customer_id, string $amount): Collection
    {
        return $this->http_post->post($this->price_rules_resource_url, [
            'price_rule' => [
                'title' => $title,
                'target_type' => 'line_item',
                'target_selection' => 'all',
                'allocation_method' => 'across',
                'value_type' => 'fixed_amount',
                'value' => $amount,
                'prerequisite_customer_ids' => [
                    $customer_id,
                ],
                'customer_selection' => 'prerequisite',
                'starts_at' => Carbon::now()->toISOString(),
            ],
        ])
        ->collect();
    }

    public function createDiscountCode(string $price_rule_id, string $code): Collection
    {
        $api_endpoint = Str::of($this->discount_resource_url)->replace('{price_rule_id}', $price_rule_id);

        return $this->http_post->post($api_endpoint, [
            'discount_code' => [
                'code' => $code,
            ],
        ])
        ->collect();
    }
}