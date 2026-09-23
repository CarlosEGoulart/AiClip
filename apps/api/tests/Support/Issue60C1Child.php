<?php

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Services\ProcessMediaAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/*
 * Issue #60 C1 test-only child runner (not a production Artisan command).
 *
 * Boots the Laravel application in an independent PHP process with a fresh
 * PostgreSQL connection, guards the authorized disposable target, observes
 * fixture state, waits on a filesystem barrier, then invokes the unchanged
 * production ProcessMediaAsset clip path with a file-recording worker double.
 *
 * Usage (spawned by the parent Pest test only):
 *   php tests/Support/Issue60C1Child.php <assetId> <idempotencyKey> <role> <runDir>
 */

$assetId = (int) ($argv[1] ?? 0);
$idempotencyKey = (string) ($argv[2] ?? '');
$role = (string) ($argv[3] ?? '');
$runDir = (string) ($argv[4] ?? '');

$result = [
    'role' => $role,
    'ok' => false,
    'error_class' => null,
    'has_clip_row' => false,
    'clip_status' => null,
    'asset_status' => null,
    'pg_pid' => null,
    'php_pid' => getmypid(),
    'preread_exists' => null,
];

$writeResult = function () use (&$result, $runDir, $role): void {
    if ($runDir !== '' && $role !== '') {
        @file_put_contents($runDir.'/'.$role.'_result.json', json_encode($result));
    }
};

try {
    if ($assetId <= 0 || $idempotencyKey === '' || ($role !== 'owner' && $role !== 'contender') || $runDir === '') {
        $result['error_class'] = 'invalid_arguments';
        $writeResult();

        exit(2);
    }

    require __DIR__.'/../../vendor/autoload.php';

    /** @var Application $app */
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    // Guard: authorized disposable target only, before any claim work.
    $guardFailed = null;
    if (! extension_loaded('pdo_pgsql')) {
        $guardFailed = 'missing_pdo_pgsql';
    } elseif (config('database.default') !== 'pgsql') {
        $guardFailed = 'unexpected_default_connection';
    } elseif (config('database.connections.pgsql.driver') !== 'pgsql') {
        $guardFailed = 'unexpected_driver';
    } elseif (config('database.connections.pgsql.database') !== 'aiclip_test_issue60') {
        $guardFailed = 'unexpected_database';
    } elseif (DB::transactionLevel() !== 0) {
        $guardFailed = 'unexpected_transaction_level';
    } else {
        $one = DB::select('SELECT 1 AS one');
        if (($one[0]->one ?? null) != 1) {
            $guardFailed = 'select_one_failed';
        } else {
            $cur = DB::select('SELECT current_database() AS db');
            $curDb = $cur[0]->db ?? null;
            if ($curDb !== 'aiclip_test_issue60') {
                $guardFailed = 'current_database_mismatch';
            } elseif (DB::connection()->getDatabaseName() !== 'aiclip_test_issue60') {
                $guardFailed = 'connection_database_mismatch';
            }
        }
    }

    if ($guardFailed !== null) {
        $result['error_class'] = $guardFailed;
        $writeResult();

        exit(3);
    }

    $pidRow = DB::select('SELECT pg_backend_pid() AS pid');
    $result['pg_pid'] = (int) ($pidRow[0]->pid ?? 0);
    @file_put_contents($runDir.'/'.$role.'_pgpid.txt', (string) $result['pg_pid']);

    // Fixture must be committed and visible to this independent connection.
    $asset = MediaAsset::find($assetId);
    if ($asset === null) {
        $result['error_class'] = 'fixture_not_visible';
        $writeResult();

        exit(4);
    }

    // Record pre-read observation before the barrier (absence expected for C1).
    $result['preread_exists'] = MediaClipAnalysis::where('media_asset_id', $assetId)->exists();
    @file_put_contents($runDir.'/'.$role.'_preread.json', json_encode([
        'exists' => $result['preread_exists'],
        'pg_pid' => $result['pg_pid'],
        'php_pid' => $result['php_pid'],
    ]));
    @file_put_contents($runDir.'/'.$role.'_ready', 'ready');

    // Deterministic barrier: wait until parent releases both callers at once.
    $deadline = microtime(true) + 20.0;
    while (! file_exists($runDir.'/release')) {
        if (microtime(true) > $deadline) {
            $result['error_class'] = 'barrier_timeout';
            $writeResult();

            exit(5);
        }
        usleep(20000);
    }

    // File-recording worker double: only the process/provider boundary is
    // doubled. Production job/claim/model/validator logic runs unchanged.
    // Calls are appended to a filesystem log so rollback cannot erase evidence
    // and the counter lock never serializes the application race (append-only).
    $workerResponse = [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => [
                    'min_duration_ms' => 5000,
                    'target_duration_ms' => 30000,
                    'max_duration_ms' => 60000,
                    'max_candidates' => 20,
                    'weights' => [
                        'duration_fit' => 50,
                        'speech_coverage' => 30,
                        'boundary_alignment' => 20,
                    ],
                ],
                'effective_weights' => [
                    'duration_fit' => 50,
                    'speech_coverage' => 0,
                    'boundary_alignment' => 0,
                ],
                'transcript_used' => false,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => [
                    'max_scenes' => 10000,
                    'max_transcript_segments' => 50000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
            ],
            'candidates' => [
                [
                    'index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 30000,
                    'rank' => 1,
                    'score' => 1,
                    'criteria' => [
                        'duration_fit' => 1,
                        'speech_coverage' => 0,
                        'boundary_alignment' => 0,
                    ],
                    'source_scene_indexes' => [0],
                ],
            ],
        ],
    ];

    $recordingAction = new class($workerResponse, $runDir, $role) extends ProcessMediaAction
    {
        public function __construct(private array $response, private string $dir, private string $role) {}

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            @file_put_contents(
                $this->dir.'/worker_calls.log',
                $this->role.':'.getmypid().PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            return $this->response;
        }

        public function probe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_probe_call');
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_scene_call');
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }
    };

    app()->instance(ProcessMediaAction::class, $recordingAction);

    $freshAsset = MediaAsset::find($assetId);
    (new ProcessMediaAsset($freshAsset, $idempotencyKey))->handle();

    $clip = MediaClipAnalysis::where('media_asset_id', $assetId)->first();
    $result['has_clip_row'] = $clip !== null;
    $result['clip_status'] = $clip?->status;
    $result['asset_status'] = $freshAsset->fresh()?->processing_status;
    $result['ok'] = true;
    $writeResult();

    exit(0);
} catch (Throwable $e) {
    // Sanitized: record only the exception class, never SQL bindings or payloads.
    $result['error_class'] = get_class($e);
    $writeResult();

    exit(6);
}
