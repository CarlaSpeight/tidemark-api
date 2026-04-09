<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'tier' => ['required', 'string', 'in:media,creator'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ];
    }
}
