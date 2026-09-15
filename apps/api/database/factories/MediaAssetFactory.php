<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mimeTypes = ['video/mp4', 'video/quicktime', 'video/webm'];
        $mime = fake()->randomElement($mimeTypes);
        $extensions = [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ];

        return [
            'project_id' => Project::factory(),
            'original_name' => fake()->uuid().'.'.$extensions[$mime],
            'storage_disk' => config('media.disk', 'media'),
            'storage_key' => Str::uuid().'.'.$extensions[$mime],
            'mime_type' => $mime,
            'size_bytes' => fake()->numberBetween(1024, 104857600),
            'status' => 'stored',
        ];
    }
}
