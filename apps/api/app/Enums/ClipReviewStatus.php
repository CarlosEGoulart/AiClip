<?php

namespace App\Enums;

enum ClipReviewStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case NEEDS_CHANGES = 'needs_changes';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::NEEDS_CHANGES => 'Needs Changes',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::NEEDS_CHANGES => 'orange',
        };
    }
}