<?php

namespace App\Http\Requests;

use App\Enums\SocialPublishStatus;
use App\Models\ClipReview;
use App\Models\SocialConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishClipRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clip_review_id' => ['required', 'integer', 'exists:clip_reviews,id'],
            'platform' => ['required', 'string', 'in:youtube,instagram,tiktok'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'clip_review_id' => 'clip review',
            'platform' => 'platform',
            'title' => 'title',
        ];
    }
}
