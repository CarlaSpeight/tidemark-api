<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModerationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:hide,approve,restore,confirm_remove,hold_for_review'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
