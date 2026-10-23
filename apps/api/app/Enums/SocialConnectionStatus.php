<?php

namespace App\Enums;

enum SocialConnectionStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';
}
