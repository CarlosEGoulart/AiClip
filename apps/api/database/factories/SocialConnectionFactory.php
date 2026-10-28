<?php

namespace Database\Factories;

use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialConnection>
 */
class SocialConnectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SocialConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => $this->faker->randomElement(['youtube', 'instagram', 'tiktok']),
            'provider_user_id' => $this->faker->uuid(),
            'display_name' => $this->faker->name(),
            'avatar_url' => $this->faker->imageUrl(),
            'channel_url' => $this->faker->url(),
            'access_token' => encrypt('encrypted_access_token_'.$this->faker->uuid()),
            'refresh_token' => encrypt('encrypted_refresh_token_'.$this->faker->uuid()),
            'access_token_expires_at' => now()->addHours(1),
            'status' => 'connected',
            'last_error' => null,
            'pending_oauth_state' => null,
            'pending_oauth_state_expires_at' => null,
        ];
    }

    /**
     * Indicate that the connection is connected.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connected',
            'access_token_expires_at' => now()->addHours(1),
            'last_error' => null,
        ]);
    }

    /**
     * Indicate that the connection is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'access_token_expires_at' => now()->subHours(1),
            'last_error' => 'Token expired',
        ]);
    }

    /**
     * Indicate that the connection is revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'revoked',
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'last_error' => 'Revoked by user',
        ]);
    }

    /**
     * Indicate that the connection has an error.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'last_error' => 'Connection failed',
        ]);
    }

    /**
     * Indicate that the connection is pending OAuth.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connected',
            'provider_user_id' => 'pending',
            'display_name' => 'Connecting…',
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'pending_oauth_state' => $this->faker->lexify(str_repeat('?', 40)),
            'pending_oauth_state_expires_at' => now()->addMinutes(10),
        ]);
    }
}
