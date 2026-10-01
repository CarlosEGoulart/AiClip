<?php

namespace Database\Factories;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DerivedAsset>
 */
class DerivedAssetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<DerivedAsset>
     */
    protected $model = DerivedAsset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => Str::uuid() . '.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => fake()->numberBetween(1024, 104857600),
            'duration_ms' => fake()->numberBetween(1000, 300000),
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ];
    }

    /**
     * Create a rendered clip derived asset.
     */
    public function renderedClip(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'status' => DerivedAsset::STATUS_PENDING,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => 'renders/' . Str::uuid() . '.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
        ]);
    }
}