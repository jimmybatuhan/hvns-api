<?php

namespace App\Shopify;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyAdmin
{
    private $api_version;
    private $admin_url;
    private $access_token;
    private $admin_api;
    private $http;

    public function __construct()
    {
        $this->api_version = config('app.shopify_api_version');
        $this->admin_url = config('app.shopify_store_url');
        $this->access_token = config('app.shopify_access_token');
        $this->admin_api = $this->admin_url . '/admin/api/' . $this->api_version;
        $this->http = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->access_token,
        ]);
    }

    public function findCustomer(array $query): Response
    {
        $filters = collect($query)->map(function ($item, $key) {
            return "$key:$item";
        })
        // TODO add more 'connectives' in the query
        ->implode(" AND ");

        return $this->http->get($this->customer_search_resource_url, [
            'query' => $filters,
        ]);
    }

    public function createCustomer(
        string $first_name,
        string $last_name,
        string $email,
        string $phone,
        string $password
    ): Response {
        return $this->http->post($this->admin_api . '/customers.json', [
            'customer' => [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'password_confirmation' => $password,
                'verified_email' => false,
            ],
        ]);
    }

    public function deleteCustomer(string $customer_id): Response
    {
        return $this->http->delete($this->admin_api . "/customers/{$customer_id}.json");
    }

    public function addCustomerMetafield(
        string $customer_id,
        string $key,
        string $value,
        string $namespace
    ): Response {
        return $this->http->put($this->admin_api . "/customers/{$customer_id}.json", [
            'customer' => [
                'id' => $customer_id,
                'metafields' => [
                    [
                        'key' => $key,
                        'value' => $value,
                        'namespace' => $namespace,
                        'value_type' => 'string',
                    ]
                ],
            ],
        ]);
    }

    public function createPriceRule(string $title, string $customer_id, string $amount): Response
    {
        return $this->http->post($this->admin_api . '/price_rules.json', [
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
        ]);
    }

    public function createDiscountCode(string $price_rule_id, string $code): Response
    {
        return $this->http->post($this->admin_api . "/price_rules/{$price_rule_id}/discount_codes.json", [
            'discount_code' => [
                'code' => $code,
            ],
        ]);
    }
}