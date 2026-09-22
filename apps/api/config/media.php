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

    /*
    |--------------------------------------------------------------------------
    | Clip Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for deterministic clip candidate analysis. These values
    | are operational settings, not score inputs or worker configuration.
    |
    */

    'clip_analysis_timeout_seconds' => (int) env('MEDIA_CLIP_ANALYSIS_TIMEOUT_SECONDS', 30),

    'clip_analysis_min_duration_ms' => (int) env('MEDIA_CLIP_ANALYSIS_MIN_DURATION_MS', 5000),

    'clip_analysis_target_duration_ms' => (int) env('MEDIA_CLIP_ANALYSIS_TARGET_DURATION_MS', 30000),

    'clip_analysis_max_duration_ms' => (int) env('MEDIA_CLIP_ANALYSIS_MAX_DURATION_MS', 60000),

    'clip_analysis_max_candidates' => (int) env('MEDIA_CLIP_ANALYSIS_MAX_CANDIDATES', 20),

    'clip_analysis_weight_duration_fit' => (int) env('MEDIA_CLIP_ANALYSIS_WEIGHT_DURATION_FIT', 50),

    'clip_analysis_weight_speech_coverage' => (int) env('MEDIA_CLIP_ANALYSIS_WEIGHT_SPEECH_COVERAGE', 30),

    'clip_analysis_weight_boundary_alignment' => (int) env('MEDIA_CLIP_ANALYSIS_WEIGHT_BOUNDARY_ALIGNMENT', 20),

];
