<?php

namespace Tests\Unit\Enums;

use App\Enums\ClipReviewStatus;
use Tests\TestCase;

uses(TestCase::class);

it('has correct enum values', function () {
    expect(ClipReviewStatus::PENDING->value)->toBe('pending');
    expect(ClipReviewStatus::APPROVED->value)->toBe('approved');
    expect(ClipReviewStatus::REJECTED->value)->toBe('rejected');
    expect(ClipReviewStatus::NEEDS_CHANGES->value)->toBe('needs_changes');
});

it('returns correct labels', function () {
    expect(ClipReviewStatus::PENDING->label())->toBe('Pending');
    expect(ClipReviewStatus::APPROVED->label())->toBe('Approved');
    expect(ClipReviewStatus::REJECTED->label())->toBe('Rejected');
    expect(ClipReviewStatus::NEEDS_CHANGES->label())->toBe('Needs Changes');
});

it('returns correct colors', function () {
    expect(ClipReviewStatus::PENDING->color())->toBe('gray');
    expect(ClipReviewStatus::APPROVED->color())->toBe('green');
    expect(ClipReviewStatus::REJECTED->color())->toBe('red');
    expect(ClipReviewStatus::NEEDS_CHANGES->color())->toBe('orange');
});

it('can be created from string', function () {
    $status = ClipReviewStatus::from('pending');
    expect($status)->toBe(ClipReviewStatus::PENDING);

    $status = ClipReviewStatus::from('approved');
    expect($status)->toBe(ClipReviewStatus::APPROVED);

    $status = ClipReviewStatus::from('rejected');
    expect($status)->toBe(ClipReviewStatus::REJECTED);

    $status = ClipReviewStatus::from('needs_changes');
    expect($status)->toBe(ClipReviewStatus::NEEDS_CHANGES);
});

it('throws on invalid value', function () {
    expect(fn () => ClipReviewStatus::from('invalid'))->toThrow(\ValueError::class);
});

it('tryFrom returns null for invalid value', function () {
    $status = ClipReviewStatus::tryFrom('invalid');
    expect($status)->toBeNull();
});
