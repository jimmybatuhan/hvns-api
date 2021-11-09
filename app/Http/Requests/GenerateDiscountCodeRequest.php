<?php

namespace App\Http\Requests\Auth;

use App\Shopify\Facades\ShopifyAdmin;
use Illuminate\Foundation\Http\FormRequest;
use App\Shopify\Constants as ShopifyConstants;

class GenerateDiscountCodeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            "mobile" => "required|bail",
            "shopify_customer_id" => "required|bail",
            "points_to_use" => "numeric|required|bail|max:" . ShopifyConstants::MAXIMUM_POINTS_TO_USE,
            "claim_500" => "required|bool",
        ];
    }
}
