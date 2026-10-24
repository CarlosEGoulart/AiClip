<?php

namespace Tests\Support;

use App\Models\User;

trait CreatesTestUsers
{
    protected function createSecondUser(): User
    {
        return User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]);
    }
}
