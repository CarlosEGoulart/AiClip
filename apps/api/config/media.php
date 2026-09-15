<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Storage Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration determines the storage disk and limits for media
    | uploads. The disk must be configured in config/filesystems.php.
    |
    */

    'disk' => env('MEDIA_DISK', 'media'),

    'max_upload_size' => (int) env('MEDIA_MAX_UPLOAD_SIZE', 104857600),

];
