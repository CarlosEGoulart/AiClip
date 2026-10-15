<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClipReviewStatusUpdateRequest extends FormRequest
{
    public function rules(): array
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
