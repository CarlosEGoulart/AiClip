<?php

namespace Database\Factories;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClipReview>
 */
class ClipReviewFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ClipReview>
     */
    protected $model = ClipReview::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'derived_asset_id' => DerivedAsset::factory()->renderedClip(),
            'reviewer_id' => User::factory(),
            'status' => ClipReviewStatus::PENDING,
            'feedback' => null,
        ];
    }
}