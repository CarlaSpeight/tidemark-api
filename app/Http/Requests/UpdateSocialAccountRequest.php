<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_name' => ['sometimes', 'string', 'max:255'],
            'account_handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'access_token_encrypted' => ['sometimes', 'string', 'max:2048'],
            'refresh_token_encrypted' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'token_expires_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
