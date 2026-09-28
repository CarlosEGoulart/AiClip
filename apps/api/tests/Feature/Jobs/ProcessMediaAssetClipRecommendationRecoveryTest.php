<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRecommendationValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Process\Process;
use Tests\Support\ClipAnalysisFixture;
use Tests\Support\Issue64RecordingAction;
use Tests\Support\Issue64RecoveryFixture as Fixture;
use Tests\TestCase;

// Sibling of the legacy RefreshDatabase suites: no outer transaction can hide fixtures.
//
// DATABASE DEPENDENCY: every case in this file requires the operator-authorized
// disposable PostgreSQL 16 target named in Issue64RecoveryFixture::guard(),
// plus the pdo_pgsql driver. This is a mandatory environment gate, not a skip:
// the guard fails closed before any fixture, migration or destructive write, so
// an absent target is reported as SETUP_BLOCKER and never as a pass. The
// accepted corrective RED evidence for issue #64 was produced here, and its
// behavioral assertions are carried forward unchanged; only the protocol and
// fixture shape were migrated to the replacement specification.
uses(TestCase::class);

beforeEach(function () {
    Fixture::guard();
    $this->recoveryLogs = new TestHandler;
    Log::swap(new Logger('issue64-recovery', [$this->recoveryLogs]));
});

it('test_no_audio_and_extraction_failure_ignore_stale_transcript', function (string $state) {
    $asset = Fixture::create('completed', $state === 'no_audio' ? null : 'aac', false);
    try {
        $observer = Fixture::observer();
        $m4 = Fixture::row($observer, 'media_clip_analyses', $asset->id);
        $action = new Issue64RecordingAction;
        (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->handle();
        $row = MediaClipRecommendation::where('media_asset_id', $asset->id)->firstOrFail();
        $references = $row->recommendations ?? [];
        $source = $asset->clipAnalysis->candidates;
        $expectedReferences = array_map(static fn ($c) => [
            'm4_candidate_index' => $c['index'], 'start_ms' => $c['start_ms'], 'end_ms' => $c['end_ms'],
            'm4_rank' => $c['rank'], 'm4_score' => $c['score'],
            'semantic_score' => null, 'semantic_rank' => null, 'reason' => $state,
        ], $source);
        $persisted = json_encode([$row->recommendations, $row->input_snapshot]);
        $checks = [
            'zero_rank_calls' => $action->rankCalls === 0,
            'stale_text_not_sent' => ! $action->sentinelSent,
            'authoritative_audio_boundary' => $action->extractionCalls === ($state === 'no_audio' ? 0 : 1),
            'durable_unavailable' => $row->status === 'unavailable' && $row->getAttribute('reason') === $state,
            'exact_unscored_references' => $references === $expectedReferences,
            'no_fabricated_inference' => ($row->parameters['inference_performed'] ?? null) === false,
            'transcript_used_false' => ($row->parameters['transcript_used'] ?? null) === false,
            'no_stale_text_in_persistence' => ! str_contains($persisted, 'SYNTHETIC_64_'),
            'no_stale_text_in_logs' => ! str_contains(json_encode($this->recoveryLogs->getRecords()), 'SYNTHETIC_64_'),
            'm4_raw_row_unchanged' => $m4 === Fixture::row($observer, 'media_clip_analyses', $asset->id),
            'extraction_failed_asset_preserved' => $state !== 'extraction_failed' || $asset->fresh()->processing_status === 'failed',
        ];
        // Evaluate every observation before asserting: one RED must not hide the other requirements.
        $this->assertSame(array_fill_keys(array_keys($checks), true), $checks,
            'Audio precedence observations: '.json_encode(['rank_calls' => $action->rankCalls, 'status' => $row->status, 'asset_status' => $asset->fresh()->processing_status]));
    } finally {
        Fixture::cleanup($asset);
    }
})->with(['no_audio' => ['no_audio'], 'extraction_failed' => ['extraction_failed']]);

it('test_completed_snapshot_binds_candidate_text_hashes', function () {
    $asset = Fixture::create();
    try {
        $observer = Fixture::observer();
        $m4 = Fixture::row($observer, 'media_clip_analyses', $asset->id);
        // Independent specification projection: normalize each overlapping segment, then join.
        $expected = [];
        foreach ($asset->clipAnalysis->candidates as $c) {
            $parts = [];
            foreach (Fixture::segments() as $s) {
                if ($s['start_ms'] < $c['end_ms'] && $s['end_ms'] > $c['start_ms']) {
                    $text = trim(preg_replace('/[\x09-\x0D\x20]+/u', ' ', $s['text']), ' ');
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
            $expected[] = ['index' => $c['index'], 'sha256' => hash('sha256', implode(' ', $parts))];
        }
        $action = new Issue64RecordingAction;
        (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->handle();
        $row = MediaClipRecommendation::where('media_asset_id', $asset->id)->firstOrFail();
        // Current-protocol controls prove this is absent binding, not a malformed response fixture.
        expect($action->rankCalls)->toBe(1);
        expect($row->status)->toBe('completed');
        $snapshot = $row->input_snapshot;
        $checks = [
            'exact_canonical_text_hashes' => ($snapshot['text_hashes'] ?? null) === $expected,
            'raw_text_absent' => ! str_contains(json_encode($snapshot), 'SYNTHETIC_64_'),
            'raw_prompt_absent' => ! str_contains(json_encode($snapshot), config('media.clip_ranking.prototype_query')),
            'm4_raw_row_unchanged' => $m4 === Fixture::row($observer, 'media_clip_analyses', $asset->id),
        ];
        $this->assertSame(array_fill_keys(array_keys($checks), true), $checks,
            'Completed persistence reached; snapshot keys: '.implode(',', array_keys($snapshot)));
    } finally {
        Fixture::cleanup($asset);
    }
});

it('test_not_ready_does_not_commit_ranking_or_finalize_owner: upstream attempts', function (string $state) {
    $asset = Fixture::create($state);
    try {
        $observer = Fixture::observer();
        expect((int) $observer->query('SELECT pg_backend_pid()')->fetchColumn())
            ->not->toBe((int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $m4 = Fixture::row($observer, 'media_clip_analyses', $asset->id);
        $action = new Issue64RecordingAction;
        (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->handle();
        $row = Fixture::row($observer, 'media_clip_recommendations', $asset->id);
        $finalTranscript = Fixture::row($observer, 'media_transcripts', $asset->id);
        $finalAsset = Fixture::row($observer, 'media_assets', $asset->id);
        // Decode recommendations and parameters before building checks
        $recommendations = json_decode($row['recommendations'], true);
        $parameters = json_decode($row['parameters'], true);

        $checks = [
            'transcribe_calls_once' => $action->transcriptionCalls === 1,
            'zero_rank_calls' => $action->rankCalls === 0,
            'final_transcript_failed' => $finalTranscript['status'] === 'failed',
            'm5_unavailable_transcription_failed' => $row !== null && $row['status'] === 'unavailable' && $row['reason'] === 'transcription_failed',
            'm4_preserved' => $m4 === Fixture::row($observer, 'media_clip_analyses', $asset->id),
            'asset_completion_follows_resolution' => $finalAsset['processing_status'] === 'completed' || $finalAsset['processing_status'] === 'failed',
            'no_ranking_content_committed' => $recommendations !== null && collect($recommendations)->every(fn ($r) => $r['semantic_score'] === null && $r['semantic_rank'] === null && $r['reason'] === 'transcription_failed') && ($parameters['inference_performed'] ?? null) === false && ($parameters['transcript_used'] ?? null) === false,
        ];
        $this->assertSame(array_fill_keys(array_keys($checks), true), $checks,
            'Upstream transcription retry observations: '.json_encode([
                'initial_state' => $state,
                'transcribe_calls' => $action->transcriptionCalls,
                'rank_calls' => $action->rankCalls,
                'final_transcript_status' => $finalTranscript['status'] ?? null,
                'm5_status' => $row['status'] ?? null,
                'm5_reason' => $row['reason'] ?? null,
                'asset_status' => $finalAsset['processing_status'] ?? null,
            ]));
    } finally {
        Fixture::cleanup($asset);
    }
})->with(['pending' => ['pending'], 'transcribing' => ['transcribing']]);

it('test_not_ready_does_not_commit_ranking_or_finalize_owner: locking and exhaustion', function (string $scenario) {
    // Ready transcript only for the lock contender: otherwise the existing upstream
    // stage resolves not-ready first. Exhaustion uses the required in-flight fixture.
    $asset = Fixture::create($scenario === 'lock_contender' ? 'completed' : 'transcribing');
    $owner = Fixture::observer();
    $observer = Fixture::observer();
    $child = null;
    $dir = sys_get_temp_dir().'/issue64-recovery-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $rec = MediaClipRecommendation::create(['media_asset_id' => $asset->id, 'status' => 'pending']);
        $m4Before = Fixture::row($observer, 'media_clip_analyses', $asset->id);
        $assetBefore = Fixture::row($observer, 'media_assets', $asset->id);
        $rowBefore = Fixture::row($observer, 'media_clip_recommendations', $asset->id);
        $owner->beginTransaction();
        $q = $owner->prepare('SELECT id FROM media_clip_recommendations WHERE media_asset_id = ? FOR UPDATE');
        $q->execute([$asset->id]);
        $ownerPid = (int) $owner->query('SELECT pg_backend_pid()')->fetchColumn();
        $child = new Process([PHP_BINARY, base_path('tests/Support/Issue64RecoveryChild.php'), (string) $asset->id,
            $scenario === 'lock_contender' ? 'handle' : 'exhaustion', $dir], base_path());
        $child->setTimeout(100);
        $child->start();
        $deadline = microtime(true) + 15;
        while (! is_file($dir.'/ready.json') && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($dir.'/ready.json', 'SETUP_BLOCKER: child must reach bootstrap barrier');
        $childPid = json_decode(file_get_contents($dir.'/ready.json'), true)['pid'];
        expect($childPid)->not->toBe($ownerPid);
        file_put_contents($dir.'/go', 'go');
        // Real pg_blocking_pids proves overlap, not a guessed delay or sleep-only race.
        $blocked = false;
        $q = $observer->prepare('SELECT ?::integer = ANY(pg_blocking_pids(?::integer))');
        $deadline = microtime(true) + 15;
        do {
            $q->execute([$ownerPid, $childPid]);
            $blocked = (bool) $q->fetchColumn();
            if ($blocked || ! $child->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertTrue($blocked, 'SETUP_BLOCKER: independent caller must reach the held-row lock barrier');
        $whileLocked = [
            'row_unchanged' => $rowBefore === Fixture::row($observer, 'media_clip_recommendations', $asset->id),
            'asset_unchanged' => $assetBefore === Fixture::row($observer, 'media_assets', $asset->id),
        ];
        if ($scenario === 'exhaustion_owner_wins') {
            // Simulate the lock owner's valid completion on its own connection.
            // Build and validate the exact current-protocol completion before
            // issuing the owner's committed write, so the write is never forged.
            $contract = MediaProcessingContract::rankClipsRequest(
                40000,
                ClipAnalysisFixture::response()['analysis']['candidates'],
                array_map(
                    static fn (array $s): string => (string) preg_replace(
                        '/^[\x09-\x0D\x20]+|[\x09-\x0D\x20]+$/u',
                        '',
                        (string) preg_replace('/[\x09-\x0D\x20]+/u', ' ', $s['text'])
                    ),
                    array_values(array_filter(
                        Fixture::segments(),
                        static fn (array $s): bool => $s['start_ms'] < 10000 && $s['end_ms'] > 0
                    ))
                ),
            );
            $completion = Fixture::completion($contract, (int) $asset->clipAnalysis->id);
            ClipRecommendationValidator::validateCompletion($completion);
            $q = $owner->prepare("UPDATE media_clip_recommendations SET status = 'completed', outcome = 'ranked', algorithm = ?, algorithm_version = ?, parameters = ?, recommendations = ?, input_snapshot = ?, execution_parameters = ? WHERE id = ?");
            $q->execute([$completion['algorithm'], $completion['algorithm_version'], json_encode($completion['parameters']),
                json_encode($completion['recommendations']), json_encode($completion['input_snapshot']),
                json_encode($completion['execution_parameters']), $rec->id]);
            $ownerTerminal = Fixture::row($owner, 'media_clip_recommendations', $asset->id);
        }
        if ($scenario === 'lock_contender') {
            // Keep the owner lock until the real production lock_timeout returns busy.
            $child->wait();
        } else {
            $owner->commit();
            $child->wait();
        }
        expect($child->getExitCode())->toBe(0, 'SETUP_BLOCKER: child helper must finish normally');
        $result = json_decode(file_get_contents($dir.'/result.json'), true);
        expect($result['setup_blocker'])->toBeFalse();
        $after = Fixture::row($observer, 'media_clip_recommendations', $asset->id);
        $checks = [
            'blocked_row_unchanged' => $whileLocked['row_unchanged'],
            'blocked_asset_unchanged' => $whileLocked['asset_unchanged'],
            'no_unexpected_child_exception' => ! isset($result['exception_class']),
            'zero_worker' => $result['rank_calls'] === 0,
            'no_committed_ranking_transition' => ! $result['visible_ranking'],
            'm4_raw_row_unchanged' => $m4Before === Fixture::row($observer, 'media_clip_analyses', $asset->id),
            'bounded_completion' => $result['finished'] && $result['elapsed_seconds'] < 90,
        ];
        if ($scenario === 'lock_contender') {
            $checks['owner_row_preserved'] = $rowBefore === $after;
            $checks['m5_not_finalized_by_contender'] = ! in_array($after['status'], ['completed', 'unavailable'], true);
            $checks['m5_recommendations_unchanged_during_lock'] = $whileLocked['row_unchanged'];
            $checks['m5_asset_unchanged_during_lock'] = $whileLocked['asset_unchanged'];
            $checks['no_semantic_output'] = $after['recommendations'] === null;
        } elseif ($scenario === 'exhaustion_owner_wins') {
            $checks['terminal_owner_row_preserved'] = $ownerTerminal === $after;
            $checks['owner_asset_preserved'] = $assetBefore === Fixture::row($observer, 'media_assets', $asset->id);
            $checks['owner_recommendations_preserved_terminal'] = $after['status'] === 'completed' && $after['outcome'] === 'ranked' && $after['recommendations'] !== null;
        } else {
            $checks['serialized_exhaustion_after_release'] = $after['status'] === 'failed' && $after['error'] === 'upstream_not_ready' && $after['recommendations'] === null;
            $checks['final_failed_upstream_not_ready'] = $after['status'] === 'failed' && $after['error'] === 'upstream_not_ready' && $after['recommendations'] === null;
            $checks['zero_worker_no_ranked_output'] = $result['rank_calls'] === 0 && $after['recommendations'] === null && ! in_array($after['outcome'] ?? '', ['ranked', 'completed'], true);
            $checks['terminal_rows_untouched'] = $m4Before === Fixture::row($observer, 'media_clip_analyses', $asset->id);
            $checks['nonterminal_asset_failed_on_exhaustion'] = Fixture::row($observer, 'media_assets', $asset->id)['processing_status'] === 'failed';
            // Asset during-lock observation scoped to M5-relevant state per test-plan.md:100
            $checks['asset_m5_state_unchanged_during_lock'] = $whileLocked['asset_unchanged'];
            $checks['no_intermediate_ranking_visibility'] = ! $result['visible_ranking'];
        }
        $this->assertSame(array_fill_keys(array_keys($checks), true), $checks,
            'Independent PostgreSQL observations: '.json_encode(['scenario' => $scenario, 'lock_barrier_reached' => $blocked,
                'elapsed_seconds' => $result['elapsed_seconds'], 'final_status' => $after['status'], 'visible_ranking' => $result['visible_ranking']]));
    } finally {
        if ($child?->isRunning()) {
            $child->stop(2);
        }
        if ($owner->inTransaction()) {
            $owner->rollBack();
        }
        Fixture::cleanup($asset);
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with([
    'lock_contender' => ['lock_contender'],
    'exhaustion_owner_wins' => ['exhaustion_owner_wins'],
    'exhaustion_unclaimed' => ['exhaustion_unclaimed'],
]);

it('arbitrates two independent first creations to one row and one worker invocation', function () {
    // Committed fixtures and two independent child callers: neither a
    // sequential refresh nor SQLite can prove this, and the loser must reread
    // the identical completed result instead of re-inferring.
    $asset = Fixture::create('completed');
    $observer = Fixture::observer();
    $m4Before = Fixture::row($observer, 'media_clip_analyses', $asset->id);
    $dir = sys_get_temp_dir().'/issue64-first-create-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $children = [];
    try {
        // Both callers observe absence before either runs.
        expect(Fixture::row($observer, 'media_clip_recommendations', $asset->id))->toBeNull();

        foreach (['a', 'b'] as $label) {
            $child = new Process([PHP_BINARY, base_path('tests/Support/Issue64RecoveryChild.php'),
                (string) $asset->id, 'handle', $dir], base_path());
            $child->setTimeout(100);
            $children[$label] = $child;
        }

        $results = [];
        foreach ($children as $label => $child) {
            $child->start();
            $deadline = microtime(true) + 15;
            while (! is_file($dir.'/ready.json') && $child->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($dir.'/ready.json', 'SETUP_BLOCKER: child must reach bootstrap barrier');
            $childPid = json_decode(file_get_contents($dir.'/ready.json'), true)['pid'];
            expect($childPid)->toBeInt();
            unlink($dir.'/ready.json');
            file_put_contents($dir.'/go', 'go');
            $child->wait();
            expect($child->getExitCode())->toBe(0, "SETUP_BLOCKER: child {$label} must finish normally");
            $results[$label] = json_decode(file_get_contents($dir.'/result.json'), true);
            unlink($dir.'/result.json');
            unlink($dir.'/go');
        }

        $row = Fixture::row($observer, 'media_clip_recommendations', $asset->id);
        $totalRankCalls = array_sum(array_map(static fn (array $r): int => (int) $r['rank_calls'], $results));

        $checks = [
            'no_setup_blocker' => array_reduce($results, static fn (bool $c, array $r): bool => $c && ! $r['setup_blocker'], true),
            'no_unexpected_child_exception' => array_reduce($results, static fn (bool $c, array $r): bool => $c && ! isset($r['exception_class']), true),
            'both_finished' => array_reduce($results, static fn (bool $c, array $r): bool => $c && $r['finished'], true),
            'one_durable_row' => $row !== null,
            'one_total_worker_invocation' => $totalRankCalls === 1,
            'terminal_completed' => ($row['status'] ?? null) === 'completed' && ($row['outcome'] ?? null) === 'ranked',
            'no_duplicate_owner' => $observer->query("SELECT count(*) FROM media_clip_recommendations WHERE media_asset_id = {$asset->id}")->fetchColumn() == 1,
            'm4_raw_row_unchanged' => $m4Before === Fixture::row($observer, 'media_clip_analyses', $asset->id),
        ];

        $this->assertSame(array_fill_keys(array_keys($checks), true), $checks,
            'Independent first-creation arbitration: '.json_encode([
                'rank_calls' => array_map(static fn (array $r): int => (int) $r['rank_calls'], $results),
                'final_status' => $row['status'] ?? null,
            ]));
    } finally {
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop(2);
            }
        }
        Fixture::cleanup($asset);
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
