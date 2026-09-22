<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaClipAnalysis;
use Tests\Support\ClipAnalysisFixture as Fixture;
use Tests\TestCase;

uses(TestCase::class);

it('validates direct model completion before any update', function (string $kind) {
    $model = new class extends MediaClipAnalysis
    {
        public array $writes = [];

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->writes[] = $attributes;

            return true;
        }
    };
    $model->status = MediaClipAnalysis::STATUS_ANALYZING;
    $analysis = Fixture::response()['analysis'];
    $request = Fixture::contract()->toMetadataArray();
    $snapshot = ['duration_ms' => $request['media']['duration_ms'], 'scenes' => $request['scenes'],
        'transcript_segments' => $request['transcript_segments'], 'configuration' => $request['configuration']];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];
    switch ($kind) {
        case 'version': $analysis['algorithm_version'] = '9.9.9';
            break;
        case 'score': $analysis['candidates'][0]['score'] = 0.9;
            break;
        case 'nan': $analysis['candidates'][0]['score'] = NAN;
            break;
        case 'infinity': $analysis['candidates'][0]['criteria']['duration_fit'] = INF;
            break;
        case 'empty': $analysis['candidates'] = [];
            break;
        case 'source': $analysis['candidates'][0]['source_scene_indexes'] = [1];
            break;
        case 'rank': $analysis['candidates'][0]['rank'] = 2;
            break;
        case 'snapshot null': $snapshot['transcript_segments'] = null;
            break;
        case 'snapshot extra': $snapshot['text'] = 'PRIVATE_SENTINEL';
            break;
        case 'timeout': $execution['timeout_seconds'] = 0;
            break;
        case 'lock wait': $execution['lock_wait_seconds'] = 34;
            break;
    }
    $failure = null;
    try {
        $model->markCompleted($analysis['algorithm'], $analysis['algorithm_version'], $analysis['parameters'],
            $analysis['candidates'], $snapshot, $execution);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    if ($kind === 'valid') {
        expect($failure)->toBeNull();
        expect($model->writes)->toHaveCount(1);
        expect($model->writes[0]['status'])->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($model->writes[0]['candidates'])->toBe($analysis['candidates']);
    } else {
        $this->assertSame([], $model->writes, 'Invalid completion must be rejected before any model update.');
        expect($failure)->toBeInstanceOf(ProcessMediaException::class);
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->not->toContain('PRIVATE_SENTINEL');
    }
})->with(['valid', 'version', 'score', 'nan', 'infinity', 'empty', 'source', 'rank', 'snapshot null', 'snapshot extra', 'timeout', 'lock wait']);

it('rejects invalid operational timeout before process creation', function (mixed $timeout) {
    config(['media.clip_analysis_timeout_seconds' => $timeout, 'logging.default' => 'null']);
    $action = Fixture::action(json_encode(Fixture::response(), JSON_THROW_ON_ERROR));
    $failure = null;
    try {
        $action->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertSame(0, $action->creations, 'Invalid timeout must fail before process creation.');
    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
})->with([0, -1, 121, true, 30.0, '30', null]);
