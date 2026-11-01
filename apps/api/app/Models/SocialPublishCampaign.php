<?php

namespace App\Models;

use App\Enums\SocialPublishCampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialPublishCampaign extends Model
{
    use HasFactory, HasRelationships;

    protected $fillable = [
        'user_id',
        'clip_review_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => SocialPublishCampaignStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clipReview(): BelongsTo
    {
        return $this->belongsTo(ClipReview::class);
    }

    public function publishes(): HasMany
    {
        return $this->hasMany(SocialPublish::class, 'campaign_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::Pending->value);
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::Processing->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::Published->value);
    }

    public function scopePartiallyPublished(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::PartiallyPublished->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::Failed->value);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', SocialPublishCampaignStatus::Cancelled->value);
    }
}
