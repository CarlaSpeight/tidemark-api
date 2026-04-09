<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'social_account_id' => ['required', 'integer', 'exists:social_accounts,id'],
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'platform_comment_id' => ['required', 'string', 'max:255'],
            'platform_post_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_platform_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platform_created_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
