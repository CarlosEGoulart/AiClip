<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;

class StoreMediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $user = $this->user();

        if (! $user || $project->user_id !== $user->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return true;
    }

    public function rules(): array
    {
        $maxSize = config('media.max_upload_size', 104857600);
        // Laravel's `max` rule for files works in kilobytes; ceil ensures
        // we don't reject a file that is exactly at the byte limit.
        $maxSizeKb = (int) ceil($maxSize / 1024);

        return [
            'file' => [
                'required',
                'file',
                'mimes:mp4,mov,webm',
                "max:{$maxSizeKb}",
            ],
        ];
    }
}
