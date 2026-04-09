<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'platform_account_id' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'access_token_encrypted' => ['required', 'string', 'max:2048'],
            'refresh_token_encrypted' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'token_expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
