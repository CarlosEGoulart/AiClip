<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreYouTubeImportRequest extends FormRequest
{
    public const YOUTUBE_URL_PATTERN = '#^(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&].*)?$#';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'regex:' . self::YOUTUBE_URL_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'The YouTube URL is required.',
            'url.string' => 'The YouTube URL must be a string.',
            'url.regex' => 'The YouTube URL is invalid.',
        ];
    }
}
