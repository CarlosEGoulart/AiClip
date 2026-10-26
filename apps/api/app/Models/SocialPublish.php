<?php

namespace App\Models;

use App\Enums\SocialPublishStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublish extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'social_connection_id',
        'clip_review_id',
        'platform',
        'status',
        'external_video_id',
        'video_url',
        'title',
        'description',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => SocialPublishStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialConnection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class);
    }

    public function clipReview(): BelongsTo
    {
        return $this->belongsTo(ClipReview::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', SocialPublishStatus::Pending->value);
    }

    public function scopeUploading($query)
    {
        return $query->where('status', SocialPublishStatus::Uploading->value);
    }

    public function scopePublished($query)
    {
        return $query->where('status', SocialPublishStatus::Published->value);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', SocialPublishStatus::Failed->value);
    }

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
