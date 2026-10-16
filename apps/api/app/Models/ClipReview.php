<?php

namespace App\Models;

use App\Enums\ClipReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClipReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'derived_asset_id',
        'reviewer_id',
        'status',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClipReviewStatus::class,
        ];
    }

    public function derivedAsset(): BelongsTo
    {
        return $this->belongsTo(DerivedAsset::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->whereHas('derivedAsset.mediaAsset.project', function ($q) use ($projectId) {
            $q->where('projects.id', $projectId);
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ClipReviewStatus::PENDING);
    }
}
