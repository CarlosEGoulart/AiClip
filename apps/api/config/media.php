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

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Python media processing worker. The worker
    | command is invoked as a subprocess to probe media files.
    |
    */

    'probe_timeout_seconds' => (int) env('MEDIA_PROBE_TIMEOUT_SECONDS', 30),

    'extract_audio_timeout_seconds' => (int) env('MEDIA_EXTRACT_AUDIO_TIMEOUT_SECONDS', 120),

    'transcribe_timeout_seconds' => (int) env('MEDIA_TRANSCRIBE_TIMEOUT_SECONDS', 300),

    'scene_detect_timeout_seconds' => (int) env('MEDIA_SCENE_DETECT_TIMEOUT_SECONDS', 120),

    'worker_command' => env('MEDIA_WORKER_COMMAND', 'python -m aiclip_worker.cli'),

];
