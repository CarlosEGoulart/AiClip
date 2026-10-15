<?php

namespace Tests\Unit\Models;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has correct fillable attributes', function () {
    $model = new ClipReview;
    expect($model->getFillable())->toContain('derived_asset_id', 'reviewer_id', 'status', 'feedback');
});

it('casts status to ClipReviewStatus enum', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);

    expect($clipReview->status)->toBeInstanceOf(ClipReviewStatus::class);
    expect($clipReview->status->value)->toBe('pending');
});

it('belongs to derived asset', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
    ]);

    expect($clipReview->derivedAsset)->toBeInstanceOf(DerivedAsset::class);
    expect($clipReview->derivedAsset->id)->toBe($derivedAsset->id);
});

it('belongs to reviewer', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
    ]);

    expect($clipReview->reviewer)->toBeInstanceOf(User::class);
    expect($clipReview->reviewer->id)->toBe($user->id);
});

it('inverse relationship: derived asset has one clip review', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
    ]);

    $derivedAsset->load('clipReview');
    expect($derivedAsset->clipReview)->toBeInstanceOf(ClipReview::class);
    expect($derivedAsset->clipReview->id)->toBe($clipReview->id);
});

it('forProject scope filters by project through derived asset chain', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $project1 = Project::factory()->create(['user_id' => $user1->id]);
    $project2 = Project::factory()->create(['user_id' => $user2->id]);

    $mediaAsset1 = MediaAsset::factory()->create(['project_id' => $project1->id]);
    $mediaAsset2 = MediaAsset::factory()->create(['project_id' => $project2->id]);

    $derivedAsset1 = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset1->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);
    $derivedAsset2 = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset2->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset1->id,
        'reviewer_id' => $user1->id,
    ]);
    ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset2->id,
        'reviewer_id' => $user2->id,
    ]);

    $results = ClipReview::forProject($project1->id)->get();
    expect($results->count())->toBe(1);
    expect($results->first()->derivedAsset->mediaAsset->project_id)->toBe($project1->id);
});

it('pending scope filters by pending status', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);
    ClipReview::factory()->create([
        'derived_asset_id' => DerivedAsset::factory()->create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        ])->id,
        'reviewer_id' => $user->id,
        'status' => ClipReviewStatus::APPROVED,
    ]);

    $results = ClipReview::pending()->get();
    expect($results->count())->toBe(1);
    expect($results->first()->status)->toBe(ClipReviewStatus::PENDING);
});

it('enforces unique constraint on derived_asset_id and reviewer_id', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
    ]);

    expect(fn () => ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

it('allows any enum value on create', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $mediaAsset = MediaAsset::factory()->create(['project_id' => $project->id]);
    $derivedAsset = DerivedAsset::factory()->create([
        'media_asset_id' => $mediaAsset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
    ]);

    foreach ([
        ClipReviewStatus::PENDING,
        ClipReviewStatus::APPROVED,
        ClipReviewStatus::REJECTED,
        ClipReviewStatus::NEEDS_CHANGES,
    ] as $status) {
        $review = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->create([
                'media_asset_id' => $mediaAsset->id,
                'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            ])->id,
            'reviewer_id' => $user->id,
            'status' => $status,
        ]);
        expect($review->status)->toBe($status);
    }
});
