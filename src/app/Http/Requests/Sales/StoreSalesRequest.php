<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseRequest;

class StoreSalesRequest extends BaseRequest
{
    /**
     * RULE CREATE
     */
    protected function rulesForCreate(): array
    {
        return [
            'outlet_id' => ['required', 'exists:outlets,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:draft,submitted'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.plate_color_id' => ['required', 'exists:plate_colors,id'],
            'items.*.pos_sold' => ['required', 'integer', 'min:0'],
            'items.*.production_sold' => ['required', 'integer', 'min:0'],
            'items.*.production_waste' => ['nullable', 'integer', 'min:0'],
            'items.*.adjustment' => ['nullable', 'integer'],
            'items.*.compensation' => ['nullable', 'integer'],

            'items.*.details' => ['nullable', 'array'],

            'items.*.details.*.menu_id' => ['required', 'exists:menus,id'],
            'items.*.details.*.menu_name' => ['required', 'string'],
            'items.*.details.*.total_produced' => ['required', 'integer', 'min:0'],
            'items.*.details.*.total_sold' => ['required', 'integer', 'min:0'],
            'items.*.details.*.total_wasted' => ['required', 'integer', 'min:0'],
            'items.*.details.*.adjustment' => ['nullable', 'integer'],
            'items.*.details.*.compensation' => ['nullable', 'integer'],
        ];
    }

    /**
     * RULE UPDATE
     */
    protected function rulesForUpdate(): array
    {
        return [
            'outlet_id' => ['sometimes', 'exists:outlets,id'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:draft,submitted'],

            'items' => ['sometimes', 'array', 'min:1'],

            'items.*.plate_color_id' => ['required_with:items', 'exists:plate_colors,id'],
            'items.*.pos_sold' => ['required_with:items', 'integer', 'min:0'],
            'items.*.production_sold' => ['required_with:items', 'integer', 'min:0'],
            'items.*.production_waste' => ['nullable', 'integer', 'min:0'],
            'items.*.adjustment' => ['nullable', 'integer'],
            'items.*.compensation' => ['nullable', 'integer'],

            'items.*.details' => ['nullable', 'array'],

            'items.*.details.*.menu_id' => ['required_with:items.*.details', 'exists:menus,id'],
            'items.*.details.*.menu_name' => ['required_with:items.*.details', 'string'],
            'items.*.details.*.total_produced' => ['required_with:items.*.details', 'integer', 'min:0'],
            'items.*.details.*.total_sold' => ['required_with:items.*.details', 'integer', 'min:0'],
            'items.*.details.*.total_wasted' => ['required_with:items.*.details', 'integer', 'min:0'],
            'items.*.details.*.adjustment' => ['nullable', 'integer'],
            'items.*.details.*.compensation' => ['nullable', 'integer'],
        ];
    }
}