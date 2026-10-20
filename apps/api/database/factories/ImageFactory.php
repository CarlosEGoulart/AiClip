<?php

namespace Database\Factories;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\Project;
use App\Services\StorageKeyBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Image>
     */
    protected $model = Image::class;

    /**
     * Define the model's default state.
     *
     * The definition array order matters: project_id resolves first so the
     * user_id and storage_key closures observe the concrete project id
     * (whether created by the nested project factory or overridden by the
     * caller), keeping every row ownership-coherent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => fn (array $attributes) => Project::query()->findOrFail($attributes['project_id'])->user_id,
            'prompt' => 'A serene mountain lake at sunrise',
            'parameters' => [
                'style_preset' => 'photorealistic',
                'width' => 1024,
                'height' => 1024,
            ],
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => fn (array $attributes) => StorageKeyBuilder::imageGeneration($attributes['project_id']),
            'mime_type' => 'image/png',
            'size_bytes' => 0,
            'width' => 1024,
            'height' => 1024,
            'status' => ImageStatus::PENDING,
        ];
    }

    /**
     * A successfully generated image.
     */
    public function generated(): static
    {
        return $this->state(fn () => [
            'status' => ImageStatus::GENERATED,
            'size_bytes' => 4096,
        ]);
    }

    /**
     * A failed image.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ImageStatus::FAILED,
        ]);
    }
}
