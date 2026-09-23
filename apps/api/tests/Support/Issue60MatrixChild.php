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
 * Issue #60 matrix test-only child runner (not a production Artisan command).
 *
 * Independent PHP process with a fresh PostgreSQL connection. Guards the
 * authorized disposable target, records its pre-read observation, waits on a
 * deterministic filesystem barrier, then invokes the unchanged production
 * ProcessMediaAsset path with a file-recording process/provider double only.
 *
 * Usage (spawned by the parent Pest matrix test only):
 *   php tests/Support/Issue60MatrixChild.php <assetId> <key> <role> <runDir> <mode> [param]
 *
 * Modes:
 *   contend : recording worker returns the golden response immediately.
 *   hold    : recording worker blocks until <runDir>/release_worker exists
 *             (bounded), proving the contender raced an open transaction.
 * Param `timeout=N` overrides media.clip_analysis_timeout_seconds in the child.
 */

$assetId = (int) ($argv[1] ?? 0);
$idempotencyKey = (string) ($argv[2] ?? '');
$role = (string) ($argv[3] ?? '');
$runDir = (string) ($argv[4] ?? '');
$mode = (string) ($argv[5] ?? 'contend');
$param = (string) ($argv[6] ?? '');

$result = [
    'role' => $role,
    'mode' => $mode,
    'ok' => false,
    'error_class' => null,
    'clip_status' => null,
    'asset_status' => null,
    'pg_pid' => null,
    'php_pid' => getmypid(),
    'preread' => null,
];

$writeResult = function () use (&$result, $runDir, $role): void {
    if ($runDir !== '' && $role !== '') {
        @file_put_contents($runDir.'/'.$role.'_result.json', json_encode($result));
    }
};

try {
    if ($assetId <= 0 || $idempotencyKey === '' || $role === '' || $runDir === '' || ! in_array($mode, ['contend', 'hold'], true)) {
        $result['error_class'] = 'invalid_arguments';
        $writeResult();

        exit(2);
    }

    require __DIR__.'/../../vendor/autoload.php';

    /** @var Application $app */
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    if (! extension_loaded('pdo_pgsql')
        || config('database.default') !== 'pgsql'
        || config('database.connections.pgsql.driver') !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'aiclip_test_issue60'
        || DB::transactionLevel() !== 0
    ) {
        $result['error_class'] = 'guard_mismatch';
        $writeResult();

        exit(3);
    }

    $one = DB::select('SELECT 1 AS one');
    $cur = DB::select('SELECT current_database() AS db');
    if (($one[0]->one ?? null) != 1
        || ($cur[0]->db ?? null) !== 'aiclip_test_issue60'
        || DB::connection()->getDatabaseName() !== 'aiclip_test_issue60'
    ) {
        $result['error_class'] = 'guard_database_mismatch';
        $writeResult();

        exit(3);
    }

    if (str_starts_with($param, 'timeout=')) {
        config(['media.clip_analysis_timeout_seconds' => (int) substr($param, 8)]);
    }

    $pidRow = DB::select('SELECT pg_backend_pid() AS pid');
    $result['pg_pid'] = (int) ($pidRow[0]->pid ?? 0);

    $asset = MediaAsset::find($assetId);
    if ($asset === null) {
        $result['error_class'] = 'fixture_not_visible';
        $writeResult();

        exit(4);
    }

    $existing = MediaClipAnalysis::where('media_asset_id', $assetId)->first();
    $result['preread'] = $existing === null ? null : $existing->status;
    @file_put_contents($runDir.'/'.$role.'_preread.json', json_encode([
        'status' => $result['preread'],
        'pg_pid' => $result['pg_pid'],
        'php_pid' => $result['php_pid'],
    ]));
    @file_put_contents($runDir.'/'.$role.'_ready', 'ready');

    $deadline = microtime(true) + 20.0;
    while (! file_exists($runDir.'/release') && ! file_exists($runDir.'/release_'.$role)) {
        if (microtime(true) > $deadline) {
            $result['error_class'] = 'barrier_timeout';
            $writeResult();

            exit(5);
        }
        usleep(20000);
    }
    @file_put_contents($runDir.'/'.$role.'_in_handle', (string) microtime(true));

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
                    'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                ],
                'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                'transcript_used' => false,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
            ],
            'candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 30000, 'rank' => 1, 'score' => 1,
                    'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                    'source_scene_indexes' => [0]],
            ],
        ],
    ];

    $recordingAction = new class($workerResponse, $runDir, $role, $mode) extends ProcessMediaAction
    {
        public function __construct(private array $response, private string $dir, private string $role, private string $mode) {}

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            @file_put_contents($this->dir.'/worker_calls.log', $this->role.':'.getmypid().PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($this->mode === 'hold') {
                @file_put_contents($this->dir.'/'.$this->role.'_entered', (string) microtime(true));
                $deadline = microtime(true) + 30.0;
                while (! file_exists($this->dir.'/release_worker')) {
                    if (microtime(true) > $deadline) {
                        break;
                    }
                    usleep(50000);
                }
            }

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
    $result['clip_status'] = $clip?->status;
    $result['asset_status'] = $freshAsset->fresh()?->processing_status;
    $result['ok'] = true;
    $writeResult();

    exit(0);
} catch (Throwable $e) {
    $result['error_class'] = get_class($e);
    $writeResult();

    exit(6);
}
