<?php

namespace App\Shopify;

use App\Shopify\Constants;
use App\Shopify\Mixins\MetafieldMixin;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function addMetafields(
        string $resource,
        string $id,
        Collection $metafields
    ): Response {
        $response = $this->http->put($this->admin_api . "/{$resource}/{$id}.json", [
            Str::singular($resource) => [
                'id' => $id,
                'metafields' => $metafields->map(function ($metafield) {

                    if (! array_key_exists('value_type', $metafield)) {
                        $metafield['value_type'] = Constants::METAFIELD_VALUE_TYPE_STRING;
                    }

                    return $metafield;
                })->toArray(),
            ],
        ]);

        if ($response->failed()) {
            Log::warning("failed to add metafield in #{$id} at {$resource} resource", $metafields->toArray());
        }

        return $response;
    }

    public function updateMetafieldById(
        string $metafield_id,
        string $value,
        string $value_type = Constants::METAFIELD_VALUE_TYPE_STRING
    ): Response {
        $response = $this->http->put($this->admin_api . "/metafields/{$metafield_id}.json", [
            'metafield' => [
                'id' => $metafield_id,
                'value' => $value,
                'value_type' => $value_type,
            ]
        ]);

        if ($response->failed()) {
            Log::warning("failed to update metafield #{$metafield_id}", [
                'value' => $value,
                'type' => $value_type
            ]);
        }

        return $response;

    }

    public function fetchMetafield(string $id, string $resource): Response
    {
        Response::mixin(new MetafieldMixin());

        return $this->http->get($this->admin_api . "/{$resource}/{$id}/metafields.json");
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

    public function getOrderById(string $order_id): Response
    {
        return $this->http->get($this->admin_api . "/orders/{$order_id}.json");
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
                "once_per_customer" => true,
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

    public function getDiscountCode(string $code): Response
    {
        return $this->http->get($this->admin_api . "/discount_codes/lookup.json?code={$code}");
    }

    public function getPriceRule(string $price_rule_id): Response
    {
        return $this->http->get($this->admin_api . "/price_rules/{$price_rule_id}.json");
    }

    public function updatePriceRuleAmount(string $price_rule_id, string $amount): Response
    {
        return $this->http->put($this->admin_api . "/price_rules/{$price_rule_id}.json", [
            'price_rule' => [
                'id' => $price_rule_id,
                'value' => $amount,
            ],
        ]);
    }

    public function getCustomerById(string $shopify_customer_id): Response
    {
        return $this->http->get($this->admin_api . "/customers/{$shopify_customer_id}.json");
    }

    public function updateCustomer(
        string $shopify_customer_id,
        string $first_name,
        string $last_name
    ): Response {
        return $this->http->put($this->admin_api . "/customers/{$shopify_customer_id}.json", [
            'customer' => [
                'first_name' => $first_name,
                'last_name' => $last_name,
            ],
        ]);
    }

    public function getCustomerOrders(string $customer_id, ?string $start_at, ?string $end_at): Response
    {
        $fields = ['id', 'name', 'total_price', 'fulfillment_status', 'created_at'];
        $filter = '';
        if ($start_at && $end_at) {
            $filter = "created_at_min={$start_at}&created_at_max={$end_at}";
        }

        $fields = 'fields=' . implode(',', $fields);

        $query = "{$fields}&{$filter}";

        return $this->http->get($this->admin_api . "/customers/{$customer_id}/orders.json?{$query}");
    }
}
