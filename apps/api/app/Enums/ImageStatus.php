<?php

namespace App\Enums;

enum ImageStatus: string
{
    case PENDING = 'pending';
    case GENERATED = 'generated';
    case FAILED = 'failed';
}
