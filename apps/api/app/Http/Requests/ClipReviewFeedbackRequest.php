<?php

namespace App\Http\Requests;

use App\Models\ClipReview;
use App\Enums\ClipReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClipReviewFeedbackRequest extends FormRequest
{
    public function authorize(Request $request): bool
    {
        $clipReview = $this->route('clipReview');

        if (! $clipReview instanceof ClipReview) {
            return false;
        }

        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // Verify ownership through the chain: user -> project -> media_asset -> derived_asset -> clip_review
        return $clipReview->derivedAsset->mediaAsset->project->user_id === $user->id;
    }

    public function rules(Request $request): array
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