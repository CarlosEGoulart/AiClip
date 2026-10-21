<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateImageRequest extends FormRequest
{
    /**
     * Fixed style preset catalog ids (slice constant). The frontend mirrors
     * this catalog as constants; no style-presets API endpoint exists.
     */
    public const STYLE_PRESET_IDS = [
        'photorealistic',
        'illustration',
        'cinematic',
        'digital_art',
    ];

    public const DEFAULT_STYLE_PRESET = 'photorealistic';

    /**
     * Allowed dimension presets (width/height pairs).
     *
     * @var array<int, array{width: int, height: int}>
     */
    public const DIMENSION_PRESETS = [
        ['width' => 1024, 'height' => 1024],
        ['width' => 1080, 'height' => 1920],
        ['width' => 1920, 'height' => 1080],
    ];

    public const DEFAULT_WIDTH = 1024;

    public const DEFAULT_HEIGHT = 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'prompt' => ['required', 'string', 'min:1', 'max:1000'],
            'style_preset' => ['sometimes', 'string', 'in:'.implode(',', self::STYLE_PRESET_IDS)],
            'width' => ['sometimes', 'integer'],
            'height' => ['sometimes', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasWidth = $this->has('width');
            $hasHeight = $this->has('height');

            if ($hasWidth !== $hasHeight) {
                $validator->errors()->add(
                    'width',
                    'The width and height fields must both be present or both be absent.',
                );

                return;
            }

            if (! $hasWidth) {
                return;
            }

            $matchesPreset = collect(self::DIMENSION_PRESETS)->contains(
                fn (array $preset): bool => (int) $this->input('width') === $preset['width']
                    && (int) $this->input('height') === $preset['height'],
            );

            if (! $matchesPreset) {
                $validator->errors()->add(
                    'width',
                    'The selected width and height pair is not an allowed dimension preset.',
                );
            }
        });
    }
}
