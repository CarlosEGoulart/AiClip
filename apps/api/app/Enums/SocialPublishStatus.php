<?php

namespace App\Enums;

enum SocialPublishStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Uploading => 'Uploading',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Uploading => 'blue',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }
}
