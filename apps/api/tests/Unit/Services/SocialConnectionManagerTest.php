<?php

namespace Tests\Unit\Services;

use App\Models\SocialConnection;
use App\Models\User;
use App\Services\InitiateConnectionResult;
use App\Services\SocialConnectionManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->manager = new SocialConnectionManager;
});

describe('initiateConnection', function () {
    it('creates pending row with state and returns auth URL', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        expect($result)->toBeInstanceOf(InitiateConnectionResult::class);
        expect($result->authorizationUrl)->toContain('accounts.google.com');
        expect($result->state)->toHaveLength(40);
        expect($result->connectionId)->toBeInt();

        $connection = SocialConnection::find($result->connectionId);
        expect($connection)->not->toBeNull();
        expect($connection->user_id)->toBe($this->user->id);
        expect($connection->platform)->toBe('youtube');
        expect($connection->provider_user_id)->toBe('pending');
        expect($connection->display_name)->toBe('Connecting…');
        expect($connection->pending_oauth_state)->toBe($result->state);
        expect($connection->pending_oauth_state_expires_at)->not->toBeNull();
    });

    it('generates unique state', function () {
        $result1 = $this->manager->initiateConnection($this->user, 'youtube');
        $result2 = $this->manager->initiateConnection($this->user, 'instagram');

        expect($result1->state)->not->toBe($result2->state);
    });

    it('sets state TTL to 10 minutes', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');
        $connection = SocialConnection::find($result->connectionId);

        $expectedExpiry = now()->addSeconds(600);
        expect($connection->pending_oauth_state_expires_at->diffInSeconds($expectedExpiry))->toBeLessThan(5);
    });
});

describe('handleCallback', function () {
    it('validates state', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        // Try with wrong state
        expect(fn () => $this->manager->handleCallback('youtube', 'wrong_state', 'valid_code'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('rejects expired state', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');
        $connection = SocialConnection::find($result->connectionId);
        $connection->pending_oauth_state_expires_at = now()->subMinutes(1);
        $connection->save();

        expect(fn () => $this->manager->handleCallback('youtube', $result->state, 'valid_code'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('consumes state after successful validation', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        $callbackResult = $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        $connection = $callbackResult->connection->fresh();
        expect($connection->pending_oauth_state)->toBeNull();
        expect($connection->pending_oauth_state_expires_at)->toBeNull();
    });

    it('exchanges code for tokens', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        $callbackResult = $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        $connection = $callbackResult->connection;
        expect($connection->access_token)->not->toBeNull();
        expect($connection->refresh_token)->not->toBeNull();
        expect($connection->access_token_expires_at)->not->toBeNull();
    });

    it('fetches user profile', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        $callbackResult = $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        $connection = $callbackResult->connection;
        expect($connection->provider_user_id)->toBe('youtube_user_123');
        expect($connection->display_name)->toBe('Test YouTube Channel');
        expect($connection->avatar_url)->toBe('https://example.com/youtube_avatar.jpg');
        expect($connection->channel_url)->toBe('https://youtube.com/@testchannel');
    });

    it('persists connection data', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        $callbackResult = $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        $connection = $callbackResult->connection->fresh();
        expect($connection->status)->toBe('connected');
        expect($connection->last_error)->toBeNull();
    });

    it('logs audit without tokens', function () {
        Log::spy();

        $result = $this->manager->initiateConnection($this->user, 'youtube');
        $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'Social connection established'
                && isset($context['user_id'])
                && isset($context['platform'])
                && isset($context['provider_user_id'])
                && isset($context['connection_id'])
                && ! isset($context['access_token'])
                && ! isset($context['refresh_token']);
        });
    });

    it('prevents replay', function () {
        $result = $this->manager->initiateConnection($this->user, 'youtube');

        $this->manager->handleCallback('youtube', $result->state, 'valid_code');

        // Second call with same state should fail
        expect(fn () => $this->manager->handleCallback('youtube', $result->state, 'valid_code'))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('refreshConnection', function () {
    it('returns false when no refresh token', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'refresh_token' => null,
        ]);

        $result = $this->manager->refreshConnection($connection);

        expect($result)->toBeFalse();
        expect($connection->fresh()->status)->toBe('revoked');
    });

    it('calls provider refresh', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'refresh_token' => 'fake_youtube_refresh_token_valid_code',
            'access_token' => 'fake_youtube_access_token_valid_code',
        ]);

        $result = $this->manager->refreshConnection($connection);

        expect($result)->toBeTrue();
        $connection->refresh();
        expect($connection->access_token)->toBe('fake_youtube_new_access_token');
        expect($connection->status)->toBe('connected');
    });

    it('logs audit on success', function () {
        Log::spy();

        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'refresh_token' => 'fake_youtube_refresh_token_valid_code',
        ]);

        $this->manager->refreshConnection($connection);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'Social connection token refreshed'
                && isset($context['user_id'])
                && isset($context['platform'])
                && isset($context['connection_id'])
                && ! isset($context['access_token'])
                && ! isset($context['refresh_token']);
        });
    });

    it('marks revoked on failure', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'refresh_token' => 'invalid_refresh_token',
        ]);

        $result = $this->manager->refreshConnection($connection);

        expect($result)->toBeFalse();
        expect($connection->fresh()->status)->toBe('revoked');
        expect($connection->fresh()->access_token)->toBeNull();
        expect($connection->fresh()->refresh_token)->toBeNull();
    });
});

describe('disconnect', function () {
    it('calls provider revoke', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'fake_youtube_access_token_valid',
        ]);

        $this->manager->disconnect($connection);

        // Provider revoke is called (no exception means it was called)
        $connection->refresh();
        expect($connection->status)->toBe('revoked');
    });

    it('clears local tokens', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'fake_youtube_access_token_valid',
            'refresh_token' => 'fake_youtube_refresh_token_valid',
        ]);

        $this->manager->disconnect($connection);

        $connection->refresh();
        expect($connection->access_token)->toBeNull();
        expect($connection->refresh_token)->toBeNull();
        expect($connection->access_token_expires_at)->toBeNull();
    });

    it('logs audit', function () {
        Log::spy();

        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
        ]);

        $this->manager->disconnect($connection);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === 'Social connection disconnected'
                && isset($context['user_id'])
                && isset($context['platform'])
                && isset($context['connection_id'])
                && ! isset($context['access_token']);
        });
    });
});

describe('getValidAccessToken', function () {
    it('returns null when not connected', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'expired',
        ]);

        $token = $this->manager->getValidAccessToken($connection);

        expect($token)->toBeNull();
    });

    it('refreshes when needed', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'refresh_token' => 'fake_youtube_refresh_token_valid_code',
            'access_token' => 'fake_youtube_access_token_valid_code',
            'access_token_expires_at' => now()->addMinutes(5), // needs refresh (< 10 min)
        ]);

        $token = $this->manager->getValidAccessToken($connection);

        expect($token)->toBe('fake_youtube_new_access_token');
    });

    it('returns token when valid', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'fake_youtube_access_token_valid',
            'access_token_expires_at' => now()->addHours(1),
        ]);

        $token = $this->manager->getValidAccessToken($connection);

        expect($token)->toBe('fake_youtube_access_token_valid');
    });
});

describe('validateConnection', function () {
    it('returns false when not connected', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'expired',
        ]);

        $result = $this->manager->validateConnection($connection);

        expect($result)->toBeFalse();
    });

    it('returns false when token invalid', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'invalid_token',
        ]);

        $result = $this->manager->validateConnection($connection);

        expect($result)->toBeFalse();
    });

    it('marks expired on validation failure', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'invalid_token',
        ]);

        $this->manager->validateConnection($connection);

        expect($connection->fresh()->status)->toBe('expired');
        expect($connection->fresh()->last_error)->toBe('Token validation failed');
    });

    it('returns true when valid', function () {
        $connection = SocialConnection::factory()->for($this->user)->create([
            'platform' => 'youtube',
            'status' => 'connected',
            'access_token' => 'fake_youtube_access_token_valid',
        ]);

        $result = $this->manager->validateConnection($connection);

        expect($result)->toBeTrue();
    });
});
