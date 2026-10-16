<?php

namespace App\Http\Requests;

use App\Enums\ClipReviewStatus;
use Illuminate\Foundation\Http\FormRequest;

class ClipReviewFeedbackRequest extends FormRequest
{
    public function rules(): array
    {
        $clipReview = $this->route('clipReview');
        $status = $clipReview?->status;

        $rules = [
            'feedback' => ['required', 'string', 'max:1000'],
        ];

        // Min 10 chars required for rejected/needs_changes
        if ($status === ClipReviewStatus::REJECTED || $status === ClipReviewStatus::NEEDS_CHANGES) {
            $rules['feedback'][] = 'min:10';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'feedback.required' => 'Feedback is required.',
            'feedback.max' => 'Feedback must not exceed 1000 characters.',
            'feedback.min' => 'Feedback must be at least 10 characters for rejected or needs_changes status.',
        ];
    }
}
