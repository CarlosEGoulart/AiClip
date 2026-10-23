<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['access_token', 'refresh_token', 'pending_oauth_state'])]
class SocialConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'provider_user_id',
        'display_name',
        'avatar_url',
        'channel_url',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'status',
        'last_error',
        'pending_oauth_state',
        'pending_oauth_state_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'pending_oauth_state_expires_at' => 'datetime',
        ];
    }

    // Scopes
    public function scopeConnected($query)
    {
        return $query->where('status', 'connected');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeRevoked($query)
    {
        return $query->where('status', 'revoked');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['connected', 'expired']);
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    protected function isTokenExpired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->access_token_expires_at
                ? $this->access_token_expires_at->isPast()
                : true,
        );
    }

    protected function needsRefresh(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'connected'
                && $this->access_token_expires_at
                && $this->access_token_expires_at->isFuture()
                && $this->access_token_expires_at->lt(now()->addMinutes(10)),
        );
    }
}
