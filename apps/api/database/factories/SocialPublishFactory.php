<?php

namespace Database\Factories;

use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishStatus;
use App\Models\ClipReview;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPublish>
 */
class SocialPublishFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SocialPublish>
     */
    protected $model = SocialPublish::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'social_connection_id' => SocialConnection::factory()->connected()->create(['platform' => 'youtube'])->id,
            'clip_review_id' => ClipReview::factory()->create(['status' => ClipReviewStatus::APPROVED])->id,
            'platform' => 'youtube',
            'status' => SocialPublishStatus::Pending->value,
            'external_video_id' => null,
            'video_url' => null,
            'title' => fake()->sentence(6),
            'description' => fake()->optional()->sentence(),
            'error_message' => null,
        ];
    }

    /**
     * Indicate that the publish is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishStatus::Pending->value,
        ]);
    }

    /**
     * Indicate that the publish is uploading.
     */
    public function uploading(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishStatus::Uploading->value,
        ]);
    }

    /**
     * Indicate that the publish is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishStatus::Published->value,
            'external_video_id' => fake()->bothify('???????????'),
            'video_url' => 'https://youtu.be/' . fake()->bothify('???????????'),
        ]);
    }

    /**
     * Indicate that the publish failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishStatus::Failed->value,
            'error_message' => 'Upload failed',
        ]);
    }
}
