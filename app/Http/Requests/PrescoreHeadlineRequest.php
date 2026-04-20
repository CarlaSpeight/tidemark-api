<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrescoreHeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'headline'            => ['required', 'string', 'max:300'],
            'caption'             => ['sometimes', 'nullable', 'string', 'max:280'],
            'topic'               => ['required', 'string', 'max:100'],
            'platforms'           => ['sometimes', 'array'],
            'platforms.*'         => ['string'],
            'image'               => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_url'           => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'video_thumbnail_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
        ];
    }
}
