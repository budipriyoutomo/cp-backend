<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BaseRequest extends FormRequest
{
    /**
     * Semua request API diizinkan.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * RULE UNIVERSAL.
     * Digabung dengan create/update rules.
     */
    protected function commonRules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * RULE CREATE.
     * Child harus override jika perlu.
     */
    protected function rulesForCreate(): array
    {
        return [];
    }

    /**
     * RULE UPDATE.
     * Child harus override jika perlu.
     */
    protected function rulesForUpdate(): array
    {
        return [];
    }

    /**
     * RULE FINAL (auto detect create/update).
     */
    public function rules(): array
    {
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH']);

        return array_merge(
            $this->commonRules(),
            $isUpdate ? $this->rulesForUpdate() : $this->rulesForCreate()
        );
    }

    /**
     * Format error seperti BaseApiController.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Auto trim & empty string jadi null.
     */
    protected function prepareForValidation()
    {
        $clean = [];

        foreach ($this->all() as $key => $value) {
            if (is_string($value)) $value = trim($value);
            if ($value === '') $value = null;
            $clean[$key] = $value;
        }

        $this->merge($clean);
    }
}
