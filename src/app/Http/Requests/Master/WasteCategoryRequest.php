<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Models\WasteCategory;
use Illuminate\Validation\Rule;

/**
 * `code` dinormalkan ke huruf besar sebelum divalidasi, supaya `default_waste`
 * dan `DEFAULT_WASTE` tidak lolos sebagai dua kategori berbeda. Bentuknya
 * dibatasi huruf/angka/garis bawah karena dipakai sebagai kunci di BI.
 */
class WasteCategoryRequest extends BaseRequest
{
    protected function prepareForValidation()
    {
        parent::prepareForValidation();

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper($this->input('code'))]);
        }
    }

    protected function rulesForCreate(): array
    {
        return [
            'code'          => $this->codeRules(Rule::unique('waste_categories', 'code')),
            'category_name' => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
        ];
    }

    protected function rulesForUpdate(): array
    {
        return [
            'code'          => $this->codeRules(Rule::unique('waste_categories', 'code')->ignore($this->route('id'))),
            'category_name' => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'sort_order'    => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
        ];
    }

    private function codeRules($unique): array
    {
        return [
            'required',
            'string',
            'max:50',
            'regex:/^[A-Z0-9_]+$/',
            Rule::notIn([WasteCategory::UNCATEGORIZED_CODE]),
            $unique,
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'  => 'Kode hanya boleh huruf, angka, dan garis bawah.',
            'code.not_in' => 'Kode ' . WasteCategory::UNCATEGORIZED_CODE . ' dicadangkan sistem.',
        ];
    }
}
