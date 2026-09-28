<?php

// Test-only concurrent caller, following the existing Issue60 child-process pattern.
use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Tests\Support\Issue64RecordingAction;
use Tests\Support\Issue64RecoveryFixture as Fixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Log::swap(new Logger('issue64-test', [new NullHandler]));
$dir = $argv[3];
$result = ['finished' => false, 'setup_blocker' => false, 'rank_calls' => 0, 'visible_ranking' => false];
try {
    Fixture::guard();
    $observer = Fixture::observer();
    $asset = MediaAsset::findOrFail((int) $argv[1]);
    $action = new Issue64RecordingAction;
    $job = new ProcessMediaAsset($asset, $asset->idempotency_key, $action);
    DB::listen(function ($query) use ($observer, $asset, &$result): void {
        if (str_starts_with(strtolower($query->sql), 'update "media_clip_recommendations"')) {
            $row = Fixture::row($observer, 'media_clip_recommendations', $asset->id);
            $result['visible_ranking'] = $result['visible_ranking'] || ($row['status'] ?? null) === 'ranking';
        }
    });
    file_put_contents($dir.'/ready.json', json_encode(['pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]));
    $deadline = microtime(true) + 15;
    while (! is_file($dir.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('SETUP_BLOCKER: release barrier timeout');
        }
        usleep(10000);
    }
    $started = microtime(true);
    try {
        if ($argv[2] === 'exhaustion') {
            $job->failed(new ProcessMediaException('upstream_not_ready'));
        } else {
            $job->handle();
        }
    } catch (Throwable $e) {
        $result['exception_class'] = get_class($e);
        $result['exception_code'] = (string) $e->getCode();
    }
    $result['elapsed_seconds'] = round(microtime(true) - $started, 3);
    $result['rank_calls'] = $action->rankCalls;
    $result['finished'] = true;
} catch (Throwable $e) {
    $result['setup_blocker'] = true;
    $result['exception_class'] = get_class($e);
}
file_put_contents($dir.'/result.json', json_encode($result));
exit($result['setup_blocker'] ? 2 : 0);
