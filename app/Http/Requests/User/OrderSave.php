<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required_without:items|nullable',
            'period' => 'required_without:items|nullable|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price',
            'subscription_id' => 'nullable|integer',
            'intent' => 'nullable|in:purchase,renew,upgrade,reset',
            'items' => 'nullable|array',
            'items.*.subscription_id' => 'required_with:items|integer',
            'items.*.period' => 'required_with:items|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price',
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
