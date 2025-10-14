<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'customer_name' => 'required|string|max:255',
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.sell_price' => 'required|numeric|min:0',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'customer_name' => $this->input('customerName', $this->input('customer_name')),
            'items' => $this->input('items'),
        ]);
    }
}
