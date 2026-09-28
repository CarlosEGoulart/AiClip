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

    /*
    |--------------------------------------------------------------------------
    | Clip Ranking Configuration (M5)
    |--------------------------------------------------------------------------
    |
    | Pinned, versioned semantic ranking configuration. The scoring profile
    | values are specification constants: only the provider selection and the
    | operational timeout are environment driven. There is no automatic
    | environment detection and no fallback provider.
    |
    */

    'clip_ranking_timeout_seconds' => env('MEDIA_CLIP_RANKING_TIMEOUT_SECONDS', '60'),

    'clip_ranking' => [

        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.',

        /*
        | Each profile is selected explicitly by MEDIA_CLIP_RANKING_PROVIDER.
        | The fake profile must never advertise the real model identity.
        */
        'profiles' => [

            'cross_encoder' => [
                'provider_name' => 'cross_encoder_ranking_provider',
                'model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
                'model_revision' => '233902d25c440f23af6f7d6e94d2946bac0bee0a',
                'runtime_profile' => 'minilm_cpu_v1',
                'normalization' => 'stable_sigmoid_half_up_6',
                'max_tokens' => 512,
                'batch_size' => 8,
                'truncation' => 'right_longest_first_512',
                'inference_performed' => true,
                'criteria' => [
                    'query_passage_relevance',
                    'quantized_score_desc',
                    'm4_rank_asc',
                    'candidate_index_asc',
                ],
            ],

            'fake' => [
                'provider_name' => 'fake_ranking_provider',
                'model_id' => 'fake-ranking-v1',
                'model_revision' => '1.0.0',
                'runtime_profile' => 'fake_v1',
                'normalization' => 'fixture_units_6',
                'max_tokens' => 0,
                'batch_size' => 0,
                'truncation' => 'none',
                'inference_performed' => false,
                'criteria' => [
                    'query_passage_relevance',
                    'quantized_score_desc',
                    'm4_rank_asc',
                    'candidate_index_asc',
                ],
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Clip Ranking Provider Selection
    |--------------------------------------------------------------------------
    |
    | Selects the ranking provider profile dispatched to the media worker.
    | Allowed values are "fake" and "cross_encoder". A null or unknown value
    | fails closed with an invalid_configuration error instead of silently
    | falling back to any provider.
    |
    */

    'clip_ranking_provider' => env('MEDIA_CLIP_RANKING_PROVIDER'),

];
