<?php

namespace Database\Factories;

use App\Enums\SocialPublishCampaignStatus;
use App\Models\SocialPublishCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPublishCampaign>
 */
class SocialPublishCampaignFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SocialPublishCampaign>
     */
    protected $model = SocialPublishCampaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clip_review_id' => \App\Models\ClipReview::factory()->create(['status' => 'approved'])->id,
            'status' => SocialPublishCampaignStatus::Pending->value,
        ];
    }

    /**
     * Indicate that the campaign is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::Pending->value,
        ]);
    }

    /**
     * Indicate that the campaign is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::Processing->value,
        ]);
    }

    /**
     * Indicate that the campaign is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::Published->value,
        ]);
    }

    /**
     * Indicate that the campaign is partially published.
     */
    public function partiallyPublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::PartiallyPublished->value,
        ]);
    }

    /**
     * Indicate that the campaign is failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::Failed->value,
        ]);
    }

    /**
     * Indicate that the campaign is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SocialPublishCampaignStatus::Cancelled->value,
        ]);
    }
}
