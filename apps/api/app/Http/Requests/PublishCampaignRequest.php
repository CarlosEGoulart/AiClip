<?php

namespace App\Http\Requests;

use App\Enums\SocialPublishStatus;
use App\Models\ClipReview;
use App\Models\SocialConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishCampaignRequest extends FormRequest
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
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.platform' => ['required', 'string', 'in:youtube,instagram,tiktok'],
            'targets.*.title' => ['required', 'string', 'max:255'],
            'targets.*.description' => ['nullable', 'string'],
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
            'targets' => 'targets',
            'targets.*.platform' => 'platform',
            'targets.*.title' => 'title',
            'targets.*.description' => 'description',
        ];
    }
}
