<?php

namespace App\Http\Requests;

use App\Support\AccessOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'password'   => 'required|string|min:6|confirmed',
            'role'         => ['required', 'string', Rule::in(AccessOptions::ROLES)],
            'departemen'   => 'required|string',
            'outlet'       => 'required|array|min:1',
            'outlet.*'     => ['string', 'max:50'],
            'module_app'   => 'required|array|min:1',
            'module_app.*' => ['string', Rule::in(AccessOptions::MODULE_APPS)],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah terdaftar.',
            'password.required' => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'role.required' => 'Role wajib dipilih.',
            'role.in' => 'Role tidak valid.',
            'module_app.*.in' => 'Module app tidak valid.',
            'departemen.required' => 'Departemen wajib diisi.',
            'outlet.required' => 'Outlet wajib diisi.',
            'outlet.array' => 'Outlet harus berupa array.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        // Same envelope as BaseRequest::failedValidation().
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'Validation failed',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
