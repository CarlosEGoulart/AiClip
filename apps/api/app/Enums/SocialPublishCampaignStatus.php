<?php

namespace App\Enums;

enum SocialPublishCampaignStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case PartiallyPublished = 'partially_published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Published => 'Published',
            self::PartiallyPublished => 'Partially Published',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'blue',
            self::Published => 'green',
            self::PartiallyPublished => 'yellow',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }
}
