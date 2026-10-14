<?php

namespace App\Http\Requests;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClipReviewStatusUpdateRequest extends FormRequest
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
        return [
            'status' => [
                'required',
                'string',
                'in:approved,rejected,needs_changes',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of: approved, rejected, needs_changes.',
        ];
    }
}